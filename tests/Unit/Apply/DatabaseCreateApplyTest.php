<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Apply;

use LaravelCloudBlueprint\Apply\ApplyStatus;
use LaravelCloudBlueprint\Apply\DatabaseClusterReadiness;
use LaravelCloudBlueprint\Apply\Contract\Delay;
use LaravelCloudBlueprint\Apply\CreateOnlyApply;
use LaravelCloudBlueprint\Blueprint\ApplicationDefinition;
use LaravelCloudBlueprint\Blueprint\Blueprint;
use LaravelCloudBlueprint\Blueprint\BlueprintSchemaVersion;
use LaravelCloudBlueprint\Blueprint\DatabaseClusterDefinition;
use LaravelCloudBlueprint\Blueprint\DatabaseClusterDefinitionCollection;
use LaravelCloudBlueprint\Blueprint\DatabaseClusterType;
use LaravelCloudBlueprint\Blueprint\EnvironmentDefinition;
use LaravelCloudBlueprint\Blueprint\EnvironmentDefinitionCollection;
use LaravelCloudBlueprint\Blueprint\LaravelMySqlConfiguration;
use LaravelCloudBlueprint\Blueprint\LogicalDatabaseDefinition;
use LaravelCloudBlueprint\Blueprint\LogicalDatabaseDefinitionCollection;
use LaravelCloudBlueprint\Blueprint\SourceDefinition;
use LaravelCloudBlueprint\Blueprint\SourceProvider;
use LaravelCloudBlueprint\Blueprint\VariableDefinitionCollection;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudDatabaseMutationClient;
use LaravelCloudBlueprint\Cloud\DTO\CloudApplication;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabase;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabaseCluster;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironment;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironmentDetails;
use LaravelCloudBlueprint\Cloud\DTO\CloudLaravelMySqlConfiguration;
use LaravelCloudBlueprint\Cloud\DTO\CloudOrganization;
use LaravelCloudBlueprint\Cloud\DTO\CreateApplicationRequest;
use LaravelCloudBlueprint\Cloud\DTO\CreateDatabaseClusterRequest;
use LaravelCloudBlueprint\Cloud\DTO\CreateDatabaseRequest;
use LaravelCloudBlueprint\Cloud\DTO\CreateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\DTO\SetEnvironmentVariablesRequest;
use LaravelCloudBlueprint\Cloud\DTO\UpdateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\DTO\UpdatedCloudEnvironment;
use LaravelCloudBlueprint\Cloud\Exception\CloudApiException;
use LaravelCloudBlueprint\Cloud\Exception\CloudTransportException;
use LaravelCloudBlueprint\Planning\CreatePlan;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\Planning\VariableValueResolver;
use LaravelCloudBlueprint\State\Contract\StateStore;
use LaravelCloudBlueprint\State\Contract\StateTransaction;
use LaravelCloudBlueprint\State\StateDocument;
use LaravelCloudBlueprint\State\StateResource;
use LaravelCloudBlueprint\State\StateVersion;
use LaravelCloudBlueprint\State\Exception\StateStorageException;
use PHPUnit\Framework\TestCase;

final class DatabaseCreateApplyTest extends TestCase
{
    public function testNewClusterAndChildrenAreCreatedAndImmediatelyCheckpointedInOrder(): void
    {
        $blueprint = self::blueprint('application', 'reporting');
        $cloud = new DatabaseMutationCloud();
        $states = new DatabaseMutationStateStore(StateDocument::empty());

        $result = self::apply($blueprint, $cloud, $states);

        self::assertSame(ApplyStatus::SUCCESS, $result->status);
        self::assertSame(['cluster:primary', 'database:application', 'database:reporting'], $cloud->mutations);
        self::assertSame(3, $states->saveCount);
        self::assertNotNull($states->state->find(new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary')));
        self::assertNotNull($states->state->find(new ResourceAddress(ResourceType::DATABASE, 'primary.application')));
        self::assertNotNull($states->state->find(new ResourceAddress(ResourceType::DATABASE, 'primary.reporting')));
    }

    public function testNewChildCanBeCreatedUnderAuthoritativelyOwnedCluster(): void
    {
        $clusterAddress = new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary');
        $state = new StateDocument(
            StateVersion::V1,
            1,
            'acme',
            new StateResource($clusterAddress, ResourceType::DATABASE_CLUSTER, 'cluster-1'),
        );
        $cloud = new DatabaseMutationCloud(clusters: [DatabaseMutationCloud::cluster()]);
        $states = new DatabaseMutationStateStore($state);

        self::apply(self::blueprint('application'), $cloud, $states);

        self::assertSame(['database:application'], $cloud->mutations);
        self::assertSame('cluster-1', $cloud->databaseCreateParents[0]);
    }

    public function testConfirmedSiblingCheckpointSurvivesLaterChildFailure(): void
    {
        $cloud = new DatabaseMutationCloud(failDatabaseName: 'reporting');
        $states = new DatabaseMutationStateStore(StateDocument::empty());

        $result = self::apply(self::blueprint('application', 'reporting'), $cloud, $states);

        self::assertSame(ApplyStatus::PARTIAL_FAILURE, $result->status);
        self::assertNotNull($states->state->find(new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary')));
        self::assertNotNull($states->state->find(new ResourceAddress(ResourceType::DATABASE, 'primary.application')));
        self::assertNull($states->state->find(new ResourceAddress(ResourceType::DATABASE, 'primary.reporting')));
    }

    public function testUncertainClusterPostIsNeverRetriedOrCheckpointed(): void
    {
        $cloud = new DatabaseMutationCloud(clusterTransportFailure: true);
        $states = new DatabaseMutationStateStore(StateDocument::empty());

        $result = self::apply(self::blueprint('application'), $cloud, $states);

        self::assertSame(ApplyStatus::PARTIAL_FAILURE, $result->status);
        self::assertSame(1, $cloud->clusterCreateCalls);
        self::assertSame(0, $states->saveCount);
        self::assertStringContainsString('uncertain', serialize($result));
    }

    public function testClusterCheckpointFailureStopsChildrenAndRequiresImportRecovery(): void
    {
        $cloud = new DatabaseMutationCloud();
        $states = new DatabaseMutationStateStore(StateDocument::empty(), failSaveAt: 1);

        $result = self::apply(self::blueprint('application'), $cloud, $states);

        self::assertSame(ApplyStatus::PARTIAL_FAILURE, $result->status);
        self::assertSame(['cluster:primary'], $cloud->mutations);
        self::assertSame([], $states->state->resources());
        self::assertStringContainsString('import', serialize($result));
    }

    public function testChildCheckpointFailureRetainsParentAndStopsLaterSibling(): void
    {
        $cloud = new DatabaseMutationCloud();
        $states = new DatabaseMutationStateStore(StateDocument::empty(), failSaveAt: 2);

        $result = self::apply(self::blueprint('application', 'reporting'), $cloud, $states);

        self::assertSame(ApplyStatus::PARTIAL_FAILURE, $result->status);
        self::assertSame(['cluster:primary', 'database:application'], $cloud->mutations);
        self::assertNotNull($states->state->find(new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary')));
        self::assertNull($states->state->find(new ResourceAddress(ResourceType::DATABASE, 'primary.application')));
    }

    public function testTransitionalClusterUsesBoundedGetOnlyReadinessAndRetainsCheckpointOnTimeout(): void
    {
        $cloud = new DatabaseMutationCloud(createdClusterStatus: 'creating', detailStatuses: ['creating', 'creating']);
        $states = new DatabaseMutationStateStore(StateDocument::empty());
        $readiness = new DatabaseClusterReadiness(new DatabaseNoDelay(), 2, 0);

        $result = self::apply(self::blueprint('application'), $cloud, $states, $readiness);

        self::assertSame(ApplyStatus::PARTIAL_FAILURE, $result->status);
        self::assertSame(2, $cloud->clusterDetailCalls);
        self::assertSame(['cluster:primary'], $cloud->mutations);
        self::assertNotNull($states->state->find(new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary')));
    }

    public function testTransitionalClusterBecomesReadyWithoutRetryingPost(): void
    {
        $cloud = new DatabaseMutationCloud(createdClusterStatus: 'creating', detailStatuses: ['creating', 'available']);
        $states = new DatabaseMutationStateStore(StateDocument::empty());

        $result = self::apply(
            self::blueprint('application'),
            $cloud,
            $states,
            new DatabaseClusterReadiness(new DatabaseNoDelay(), 2, 0),
        );

        self::assertSame(ApplyStatus::SUCCESS, $result->status);
        self::assertSame(1, $cloud->clusterCreateCalls);
        self::assertSame(['cluster:primary', 'database:application'], $cloud->mutations);
    }

    public function testOnlyAvailableIsReadyAndOnlyCreatingIsWaitable(): void
    {
        $readiness = new DatabaseClusterReadiness(new DatabaseNoDelay(), 1, 0);
        $availableCloud = new DatabaseMutationCloud();
        self::assertSame('available', $readiness->wait(
            $availableCloud,
            DatabaseMutationCloud::clusterWithStatus('cluster-1', 'available'),
        )->status);
        self::assertSame(0, $availableCloud->clusterDetailCalls);

        $stopStatuses = [
            'updating', 'restarting', 'upgrading', 'moving', 'stopped', 'restoring', 'restore_failed',
            'disabled', 'snapshotting_before_archiving', 'archiving', 'archived', 'deleting', 'deleted', 'unknown',
            'pending', 'provisioning', 'failed', 'unavailable', 'future-status',
        ];
        foreach ($stopStatuses as $status) {
            $cloud = new DatabaseMutationCloud();
            try {
                $readiness->wait($cloud, DatabaseMutationCloud::clusterWithStatus('cluster-1', $status));
                self::fail('Expected readiness refusal for status ' . $status);
            } catch (\LaravelCloudBlueprint\Cloud\Exception\CloudResponseException) {
                self::assertSame(0, $cloud->clusterDetailCalls, $status . ' must not be waitable.');
            }
        }
    }

    public function testUnmanagedClusterAppearingDuringLockedRevalidationPreventsPost(): void
    {
        $cloud = new DatabaseMutationCloud(clusterAppearsOnRead: 2);
        $states = new DatabaseMutationStateStore(StateDocument::empty());

        $result = self::apply(self::blueprint('application'), $cloud, $states);

        self::assertSame(ApplyStatus::FAILED, $result->status);
        self::assertSame(0, $cloud->clusterCreateCalls);
        self::assertStringContainsString('Import', serialize($result));
    }

    public function testConcurrentStateOwnershipIsReplannedWithoutDuplicatePost(): void
    {
        $clusterAddress = new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary');
        $owned = new StateDocument(
            StateVersion::V1,
            1,
            'acme',
            new StateResource($clusterAddress, ResourceType::DATABASE_CLUSTER, 'cluster-1'),
        );
        $cloud = new DatabaseMutationCloud(clusterAppearsOnRead: 2);
        $states = new DatabaseMutationStateStore(StateDocument::empty(), stateAtBegin: $owned);

        $result = self::apply(self::blueprint(), $cloud, $states);

        self::assertSame(ApplyStatus::SUCCESS, $result->status);
        self::assertSame(0, $cloud->clusterCreateCalls);
    }

    private static function apply(
        Blueprint $blueprint,
        DatabaseMutationCloud $cloud,
        DatabaseMutationStateStore $states,
        ?DatabaseClusterReadiness $readiness = null,
    ): \LaravelCloudBlueprint\Apply\ApplyResult {
        $values = new VariableValueResolver(new DatabaseEmptyValues());
        $plan = (new CreatePlan($values))->create($blueprint, $cloud, $states->state);
        return (new CreateOnlyApply($values, $readiness ?? new DatabaseClusterReadiness()))
            ->execute($blueprint, $plan, $cloud, $states);
    }

    private static function blueprint(string ...$databases): Blueprint
    {
        $definitions = array_map(static fn (string $name): LogicalDatabaseDefinition => new LogicalDatabaseDefinition($name), $databases);
        return new Blueprint(
            BlueprintSchemaVersion::V1,
            'acme',
            new ApplicationDefinition('my-api', 'eu-central-1', new SourceDefinition(SourceProvider::GITHUB, 'acme/my-api')),
            new EnvironmentDefinitionCollection(new EnvironmentDefinition('production', 'main', new VariableDefinitionCollection())),
            new DatabaseClusterDefinitionCollection(new DatabaseClusterDefinition(
                'primary',
                DatabaseClusterType::LARAVEL_MYSQL_8,
                'eu-central-1',
                new LaravelMySqlConfiguration('size', 5, 1, false, false),
                new LogicalDatabaseDefinitionCollection(...$definitions),
            )),
        );
    }
}

final class DatabaseEmptyValues implements \LaravelCloudBlueprint\Planning\Contract\EnvironmentValueProvider
{
    public function value(string $name): ?string { return null; }
}

final class DatabaseNoDelay implements Delay
{
    public function milliseconds(int $milliseconds): void {}
}

final class DatabaseMutationCloud implements LaravelCloudDatabaseMutationClient
{
    /** @var list<string> */
    public array $mutations = [];
    /** @var list<string> */
    public array $databaseCreateParents = [];
    public int $clusterCreateCalls = 0;
    public int $clusterDetailCalls = 0;
    public int $clusterListCalls = 0;
    /** @var list<CloudDatabase> */
    private array $createdDatabases = [];

    /**
     * @param list<CloudDatabaseCluster> $clusters
     * @param list<string> $detailStatuses
     */
    public function __construct(
        private array $clusters = [],
        private readonly ?string $failDatabaseName = null,
        private readonly bool $clusterTransportFailure = false,
        private readonly string $createdClusterStatus = 'available',
        private array $detailStatuses = [],
        private readonly ?int $clusterAppearsOnRead = null,
    ) {
    }

    public static function cluster(string $id = 'cluster-1'): CloudDatabaseCluster
    {
        return self::clusterWithStatus($id, 'available');
    }

    public static function clusterWithStatus(string $id, string $status): CloudDatabaseCluster
    {
        return new CloudDatabaseCluster($id, 'primary', 'laravel_mysql_8', $status, 'eu-central-1',
            new CloudLaravelMySqlConfiguration('size', 5, 1, false, false));
    }

    public function organization(): CloudOrganization { return new CloudOrganization('org-1', 'Acme', 'acme'); }
    public function applications(): array { return [new CloudApplication('app-1', 'my-api', 'my-api', 'eu-central-1', 'acme/my-api')]; }
    public function environments(string $applicationId): array { return [new CloudEnvironment('env-1', $applicationId, 'production', 'main')]; }
    public function environment(string $environmentId): CloudEnvironmentDetails { throw new \LogicException('Unexpected.'); }
    public function databaseClusters(): array
    {
        ++$this->clusterListCalls;
        if ($this->clusterAppearsOnRead !== null && $this->clusterListCalls >= $this->clusterAppearsOnRead) {
            return [self::cluster()];
        }
        return $this->clusters;
    }
    public function databaseCluster(string $clusterId): CloudDatabaseCluster
    {
        ++$this->clusterDetailCalls;
        $status = array_shift($this->detailStatuses) ?? $this->createdClusterStatus;
        return self::clusterWithStatus($clusterId, $status);
    }
    public function databases(string $clusterId): array { return $this->createdDatabases; }
    public function database(string $clusterId, string $databaseId): CloudDatabase { throw new \LogicException('Unexpected.'); }

    public function createDatabaseCluster(CreateDatabaseClusterRequest $request): CloudDatabaseCluster
    {
        ++$this->clusterCreateCalls;
        if ($this->clusterTransportFailure) {
            throw new CloudTransportException('Cluster create outcome is uncertain; inspect Cloud and import before retrying.', 'POST', '/databases/clusters');
        }
        $this->mutations[] = 'cluster:' . $request->name;
        $cluster = self::clusterWithStatus('cluster-1', $this->createdClusterStatus);
        $this->clusters[] = $cluster;
        return $cluster;
    }

    public function createDatabase(string $clusterId, CreateDatabaseRequest $request): CloudDatabase
    {
        $this->databaseCreateParents[] = $clusterId;
        if ($request->name === $this->failDatabaseName) {
            throw new CloudApiException('Database API failed safely.', 'POST', '/databases/clusters/{id}/databases', 500);
        }
        $this->mutations[] = 'database:' . $request->name;
        $database = new CloudDatabase('database-' . $request->name, $clusterId, $request->name);
        $this->createdDatabases[] = $database;
        return $database;
    }

    public function createApplication(CreateApplicationRequest $request): CloudApplication { throw new \LogicException('Unexpected mutation.'); }
    public function createEnvironment(string $applicationId, CreateEnvironmentRequest $request): CloudEnvironment { throw new \LogicException('Unexpected mutation.'); }
    public function updateEnvironment(string $environmentId, UpdateEnvironmentRequest $request): UpdatedCloudEnvironment { throw new \LogicException('Unexpected mutation.'); }
    public function setEnvironmentVariables(string $environmentId, SetEnvironmentVariablesRequest $request): void { throw new \LogicException('Unexpected mutation.'); }
}

final class DatabaseMutationStateStore implements StateStore, StateTransaction
{
    public int $saveCount = 0;
    public function __construct(
        public StateDocument $state,
        private readonly ?int $failSaveAt = null,
        private readonly ?StateDocument $stateAtBegin = null,
    ) {}
    public function load(): StateDocument { return $this->state; }
    public function begin(): StateTransaction
    {
        if ($this->stateAtBegin !== null) {
            $this->state = $this->stateAtBegin;
        }
        return $this;
    }
    public function release(): void {}
    public function save(StateDocument $state): StateDocument
    {
        ++$this->saveCount;
        if ($this->failSaveAt === $this->saveCount) {
            throw new StateStorageException('Simulated state checkpoint failure.');
        }
        return $this->state = new StateDocument(StateVersion::V1, $this->state->serial + 1, $state->organization, ...$state->resources());
    }
}
