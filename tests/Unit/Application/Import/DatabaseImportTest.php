<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Application\Import;

use LaravelCloudBlueprint\Application\Import\CreateImportProposal;
use LaravelCloudBlueprint\Application\Import\ImportRefusedException;
use LaravelCloudBlueprint\Application\Import\ImportResources;
use LaravelCloudBlueprint\Application\Import\ImportStatus;
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
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudDatabaseClient;
use LaravelCloudBlueprint\Cloud\DTO\CloudApplication;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabase;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabaseCluster;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironment;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironmentDetails;
use LaravelCloudBlueprint\Cloud\DTO\CloudLaravelMySqlConfiguration;
use LaravelCloudBlueprint\Cloud\DTO\CloudOrganization;
use LaravelCloudBlueprint\Cloud\DTO\CreateApplicationRequest;
use LaravelCloudBlueprint\Cloud\DTO\CreateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\DTO\SetEnvironmentVariablesRequest;
use LaravelCloudBlueprint\Cloud\DTO\UpdateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\DTO\UpdatedCloudEnvironment;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\State\Contract\StateStore;
use LaravelCloudBlueprint\State\Contract\StateTransaction;
use LaravelCloudBlueprint\State\StateDocument;
use LaravelCloudBlueprint\State\StateResource;
use LaravelCloudBlueprint\State\StateVersion;
use PHPUnit\Framework\TestCase;

final class DatabaseImportTest extends TestCase
{
    public function testExactClusterAndLogicalDatabaseAreImportableInParentFirstOrder(): void
    {
        $proposal = self::proposal();

        self::assertSame(
            ['application.my-api', 'environment.production', 'database_cluster.primary', 'database.primary.application'],
            array_map(static fn ($candidate): string => (string) $candidate->address, iterator_to_array($proposal, false)),
        );
        self::assertSame(ImportStatus::IMPORTABLE, self::candidate($proposal, 'database_cluster.primary')->status);
        self::assertSame('cluster-1', self::candidate($proposal, 'database_cluster.primary')->remoteId);
        self::assertSame(ImportStatus::IMPORTABLE, self::candidate($proposal, 'database.primary.application')->status);
        self::assertSame('database-1', self::candidate($proposal, 'database.primary.application')->remoteId);
        self::assertSame('database_cluster.primary', (string) self::candidate($proposal, 'database.primary.application')->parent);
    }

    public function testMissingAndAmbiguousClustersBlockTheirChildren(): void
    {
        $missing = self::proposal(clusters: []);
        self::assertSame(ImportStatus::UNSUPPORTED, self::candidate($missing, 'database_cluster.primary')->status);
        self::assertSame(ImportStatus::UNSUPPORTED, self::candidate($missing, 'database.primary.application')->status);

        $ambiguous = self::proposal(clusters: [self::cluster('cluster-1'), self::cluster('cluster-2')]);
        self::assertSame(ImportStatus::CONFLICT, self::candidate($ambiguous, 'database_cluster.primary')->status);
        self::assertSame(ImportStatus::CONFLICT, self::candidate($ambiguous, 'database.primary.application')->status);
    }

    public function testTypeAndRegionCompatibilityAreRequiredButConfigurationIsNot(): void
    {
        $wrongType = self::cluster(type: 'neon_serverless_postgres_18');
        self::assertSame(ImportStatus::UNSUPPORTED,
            self::candidate(self::proposal(clusters: [$wrongType]), 'database_cluster.primary')->status);

        $wrongRegion = self::cluster(region: 'us-east-1');
        self::assertSame(ImportStatus::UNSUPPORTED,
            self::candidate(self::proposal(clusters: [$wrongRegion]), 'database_cluster.primary')->status);

        $differentConfig = self::cluster(configuration: new CloudLaravelMySqlConfiguration('other-size', 999, 30, true, true));
        self::assertSame(ImportStatus::IMPORTABLE,
            self::candidate(self::proposal(clusters: [$differentConfig]), 'database_cluster.primary')->status);
    }

    public function testMissingAndAmbiguousLogicalDatabasesAreNotImportable(): void
    {
        $missing = self::proposal(databases: []);
        self::assertSame(ImportStatus::UNSUPPORTED, self::candidate($missing, 'database.primary.application')->status);

        $ambiguous = self::proposal(databases: [self::database('database-1'), self::database('database-2')]);
        self::assertSame(ImportStatus::CONFLICT, self::candidate($ambiguous, 'database.primary.application')->status);
    }

    public function testAlreadyManagedDatabaseGraphIsIdempotent(): void
    {
        $proposal = self::proposal(state: self::databaseState());

        self::assertSame(ImportStatus::ALREADY_MANAGED, self::candidate($proposal, 'database_cluster.primary')->status);
        self::assertSame(ImportStatus::ALREADY_MANAGED, self::candidate($proposal, 'database.primary.application')->status);
    }

    public function testReverseRemoteIdentityOwnershipConflicts(): void
    {
        $state = StateDocument::empty()
            ->withResource(new StateResource(
                new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'other'),
                ResourceType::DATABASE_CLUSTER,
                'cluster-1',
            ));

        self::assertSame(
            ImportStatus::CONFLICT,
            self::candidate(self::proposal(state: $state), 'database_cluster.primary')->status,
        );
    }

    public function testOwnedStaleClusterAndDatabaseIdentitiesAreRefused(): void
    {
        $staleCluster = self::proposal(state: self::databaseState(), clusters: []);
        self::assertSame(ImportStatus::UNSUPPORTED, self::candidate($staleCluster, 'database_cluster.primary')->status);

        $staleDatabase = self::proposal(state: self::databaseState(), databases: []);
        self::assertSame(ImportStatus::UNSUPPORTED, self::candidate($staleDatabase, 'database.primary.application')->status);
    }

    public function testCompleteImportIsAtomicAndRepeatedImportDoesNotSaveAgain(): void
    {
        $cloud = DatabaseImportCloud::matching();
        $store = new DatabaseImportStateStore(StateDocument::empty());
        $imports = new ImportResources(new CreateImportProposal());

        $first = $imports->execute(self::blueprint(), $cloud, $store);
        self::assertSame(4, $first->adoptedCount());
        self::assertSame(1, $store->saveCount);
        self::assertSame(1, $first->state->serial);
        self::assertNotNull($first->state->find(new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary')));
        self::assertNotNull($first->state->find(new ResourceAddress(ResourceType::DATABASE, 'primary.application')));

        $second = $imports->execute(self::blueprint(), $cloud, $store);
        self::assertSame(0, $second->adoptedCount());
        self::assertSame(1, $store->saveCount);
        self::assertSame(1, $second->state->serial);
        self::assertSame(0, $cloud->mutationCalls);
    }

    public function testChildConflictBlocksClusterApplicationAndEnvironmentAdoption(): void
    {
        $cloud = DatabaseImportCloud::matching(databases: []);
        $store = new DatabaseImportStateStore(StateDocument::empty());

        try {
            (new ImportResources(new CreateImportProposal()))->execute(self::blueprint(), $cloud, $store);
            self::fail('Expected atomic import refusal.');
        } catch (ImportRefusedException) {
            self::assertSame(0, $store->saveCount);
            self::assertSame([], $store->state->resources());
        }
    }

    public function testLockedRediscoveryRefusesDisappearingClusterOrDatabase(): void
    {
        $clusterRace = new DatabaseImportCloud(
            clusterSnapshots: [[self::cluster()], []],
            databaseSnapshots: [[self::database()]],
        );
        $clusterStore = new DatabaseImportStateStore(StateDocument::empty());
        $imports = new ImportResources(new CreateImportProposal());
        $imports->preview(self::blueprint(), $clusterRace, $clusterStore);
        try {
            $imports->execute(self::blueprint(), $clusterRace, $clusterStore);
            self::fail('Expected Cluster race refusal.');
        } catch (ImportRefusedException) {
            self::assertSame(0, $clusterStore->saveCount);
        }

        $databaseRace = new DatabaseImportCloud(
            clusterSnapshots: [[self::cluster()]],
            databaseSnapshots: [[self::database()], []],
        );
        $databaseStore = new DatabaseImportStateStore(StateDocument::empty());
        $imports->preview(self::blueprint(), $databaseRace, $databaseStore);
        try {
            $imports->execute(self::blueprint(), $databaseRace, $databaseStore);
            self::fail('Expected Database race refusal.');
        } catch (ImportRefusedException) {
            self::assertSame(0, $databaseStore->saveCount);
        }
    }

    /**
     * @param list<CloudDatabaseCluster>|null $clusters
     * @param list<CloudDatabase>|null $databases
     */
    private static function proposal(
        ?StateDocument $state = null,
        ?array $clusters = null,
        ?array $databases = null,
    ): \LaravelCloudBlueprint\Application\Import\ImportProposal {
        $clusters ??= [self::cluster()];
        $databases ??= [self::database()];
        return (new CreateImportProposal())->create(
            self::blueprint(),
            $state ?? StateDocument::empty(),
            [self::application()],
            [self::environment()],
            $clusters,
            ['cluster-1' => $databases],
        );
    }

    private static function candidate(
        \LaravelCloudBlueprint\Application\Import\ImportProposal $proposal,
        string $address,
    ): \LaravelCloudBlueprint\Application\Import\ImportCandidate {
        foreach ($proposal as $candidate) {
            if ((string) $candidate->address === $address) {
                return $candidate;
            }
        }
        throw new \LogicException('Missing candidate ' . $address);
    }

    private static function databaseState(): StateDocument
    {
        $cluster = new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary');
        return new StateDocument(
            StateVersion::V1,
            0,
            null,
            new StateResource($cluster, ResourceType::DATABASE_CLUSTER, 'cluster-1'),
            new StateResource(
                new ResourceAddress(ResourceType::DATABASE, 'primary.application'),
                ResourceType::DATABASE,
                'database-1',
                $cluster,
            ),
        );
    }

    private static function blueprint(): Blueprint
    {
        return new Blueprint(
            BlueprintSchemaVersion::V1,
            'acme',
            new ApplicationDefinition('my-api', 'eu-central-1', new SourceDefinition(SourceProvider::GITHUB, 'acme/my-api')),
            new EnvironmentDefinitionCollection(new EnvironmentDefinition(
                'production', 'main', new VariableDefinitionCollection(),
            )),
            new DatabaseClusterDefinitionCollection(new DatabaseClusterDefinition(
                'primary',
                DatabaseClusterType::LARAVEL_MYSQL_8,
                'eu-central-1',
                new LaravelMySqlConfiguration('size', 5, 1, false, false),
                new LogicalDatabaseDefinitionCollection(new LogicalDatabaseDefinition('application')),
            )),
        );
    }

    public static function application(): CloudApplication
    {
        return new CloudApplication('app-1', 'my-api', 'my-api', 'eu-central-1', 'acme/my-api');
    }

    public static function environment(): CloudEnvironment
    {
        return new CloudEnvironment('env-1', 'app-1', 'production', 'main');
    }

    public static function cluster(
        string $id = 'cluster-1',
        string $type = 'laravel_mysql_8',
        string $region = 'eu-central-1',
        ?CloudLaravelMySqlConfiguration $configuration = null,
    ): CloudDatabaseCluster {
        return new CloudDatabaseCluster(
            $id, 'primary', $type, 'available', $region,
            $configuration ?? new CloudLaravelMySqlConfiguration('size', 5, 1, false, false),
        );
    }

    public static function database(string $id = 'database-1'): CloudDatabase
    {
        return new CloudDatabase($id, 'cluster-1', 'application');
    }
}

final class DatabaseImportCloud implements LaravelCloudDatabaseClient
{
    public int $mutationCalls = 0;
    private int $clusterRead = 0;
    private int $databaseRead = 0;

    /**
     * @param list<list<CloudDatabaseCluster>> $clusterSnapshots
     * @param list<list<CloudDatabase>> $databaseSnapshots
     */
    public function __construct(
        private array $clusterSnapshots,
        private array $databaseSnapshots,
    ) {
    }

    /** @param list<CloudDatabase>|null $databases */
    public static function matching(?array $databases = null): self
    {
        return new self([[DatabaseImportTest::cluster()]], [$databases ?? [DatabaseImportTest::database()]]);
    }

    public function organization(): CloudOrganization { return new CloudOrganization('org-1', 'Acme', 'acme'); }
    public function applications(): array { return [DatabaseImportTest::application()]; }
    public function environments(string $applicationId): array { return [DatabaseImportTest::environment()]; }
    public function environment(string $environmentId): CloudEnvironmentDetails { throw new \LogicException('Unexpected detail read.'); }

    public function databaseClusters(): array
    {
        $index = min($this->clusterRead++, count($this->clusterSnapshots) - 1);
        return $this->clusterSnapshots[$index];
    }

    public function databases(string $clusterId): array
    {
        $index = min($this->databaseRead++, count($this->databaseSnapshots) - 1);
        return $this->databaseSnapshots[$index];
    }

    public function databaseCluster(string $clusterId): CloudDatabaseCluster { throw new \LogicException('Unexpected detail read.'); }
    public function database(string $clusterId, string $databaseId): CloudDatabase { throw new \LogicException('Unexpected detail read.'); }
    public function createApplication(CreateApplicationRequest $request): CloudApplication { ++$this->mutationCalls; throw new \LogicException('Mutation.'); }
    public function createEnvironment(string $applicationId, CreateEnvironmentRequest $request): CloudEnvironment { ++$this->mutationCalls; throw new \LogicException('Mutation.'); }
    public function updateEnvironment(string $environmentId, UpdateEnvironmentRequest $request): UpdatedCloudEnvironment { ++$this->mutationCalls; throw new \LogicException('Mutation.'); }
    public function setEnvironmentVariables(string $environmentId, SetEnvironmentVariablesRequest $request): void { ++$this->mutationCalls; throw new \LogicException('Mutation.'); }
}

final class DatabaseImportStateStore implements StateStore, StateTransaction
{
    public int $saveCount = 0;

    public function __construct(public StateDocument $state)
    {
    }

    public function load(): StateDocument { return $this->state; }
    public function begin(): StateTransaction { return $this; }
    public function release(): void {}

    public function save(StateDocument $state): StateDocument
    {
        ++$this->saveCount;
        $this->state = new StateDocument(
            StateVersion::V1,
            $this->state->serial + 1,
            $state->organization,
            ...$state->resources(),
        );
        return $this->state;
    }
}
