<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Planning;

use LaravelCloudBlueprint\Apply\CreateOnlyApply;
use LaravelCloudBlueprint\Apply\Exception\ApplyRefusedException;
use LaravelCloudBlueprint\Blueprint\ApplicationDefinition;
use LaravelCloudBlueprint\Blueprint\Blueprint;
use LaravelCloudBlueprint\Blueprint\BlueprintSchemaVersion;
use LaravelCloudBlueprint\Blueprint\DatabaseClusterDefinition;
use LaravelCloudBlueprint\Blueprint\DatabaseClusterDefinitionCollection;
use LaravelCloudBlueprint\Blueprint\DatabaseClusterType;
use LaravelCloudBlueprint\Blueprint\DatabaseReference;
use LaravelCloudBlueprint\Blueprint\EnvironmentDefinition;
use LaravelCloudBlueprint\Blueprint\EnvironmentDefinitionCollection;
use LaravelCloudBlueprint\Blueprint\LaravelMySqlConfiguration;
use LaravelCloudBlueprint\Blueprint\LogicalDatabaseDefinition;
use LaravelCloudBlueprint\Blueprint\LogicalDatabaseDefinitionCollection;
use LaravelCloudBlueprint\Blueprint\NeonPostgresConfiguration;
use LaravelCloudBlueprint\Blueprint\SourceDefinition;
use LaravelCloudBlueprint\Blueprint\SourceProvider;
use LaravelCloudBlueprint\Blueprint\VariableDefinitionCollection;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudDatabaseClient;
use LaravelCloudBlueprint\Cloud\DTO\CloudApplication;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabase;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabaseCluster;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironment;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironmentDetails;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironmentVariableCollection;
use LaravelCloudBlueprint\Cloud\DTO\CloudLaravelMySqlConfiguration;
use LaravelCloudBlueprint\Cloud\DTO\CloudNeonPostgresConfiguration;
use LaravelCloudBlueprint\Cloud\DTO\CloudOrganization;
use LaravelCloudBlueprint\Cloud\DTO\CloudUnknownDatabaseConfiguration;
use LaravelCloudBlueprint\Cloud\DTO\CreateApplicationRequest;
use LaravelCloudBlueprint\Cloud\DTO\CreateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\DTO\SetEnvironmentVariablesRequest;
use LaravelCloudBlueprint\Cloud\DTO\UpdateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\DTO\UpdatedCloudEnvironment;
use LaravelCloudBlueprint\Planning\CreatePlan;
use LaravelCloudBlueprint\Planning\ExecutionPlan;
use LaravelCloudBlueprint\Planning\PlanAction;
use LaravelCloudBlueprint\Planning\PlanChange;
use LaravelCloudBlueprint\Planning\PlanOperation;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\Planning\VariableValueResolver;
use LaravelCloudBlueprint\State\StateDocument;
use LaravelCloudBlueprint\State\StateResource;
use LaravelCloudBlueprint\State\StateVersion;
use LaravelCloudBlueprint\State\Contract\StateStore;
use LaravelCloudBlueprint\State\Contract\StateTransaction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DatabasePlanningTest extends TestCase
{
    public function testBlueprintWithoutDatabasesMakesNoDatabaseRequests(): void
    {
        $cloud = new DatabasePlanningCloud();

        self::plan(self::blueprint(database: false), $cloud);

        self::assertSame(0, $cloud->clusterCalls);
        self::assertSame([], $cloud->databaseCalls);
    }

    public function testMissingClusterAndItsLogicalDatabasesAreCreatedWhileAttachmentRemainsUnsupported(): void
    {
        $plan = self::plan(self::blueprint(), new DatabasePlanningCloud(clusters: []));

        self::assertSame(PlanOperation::CREATE, self::action($plan, 'database_cluster.primary')->operation);
        self::assertSame(PlanOperation::CREATE, self::action($plan, 'database.primary.application')->operation);
        self::assertSame(PlanOperation::UNSUPPORTED, self::action($plan, 'database_attachment.production')->operation);
        self::assertSame(1, $plan->countByOperation(PlanOperation::UNSUPPORTED));
    }

    public function testExactMysqlClusterDatabaseAndAttachmentAreReadOnlyNoChange(): void
    {
        $cloud = self::matchingCloud();
        $plan = self::plan(self::blueprint(), $cloud);

        self::assertSame(PlanOperation::NO_CHANGE, self::action($plan, 'database_cluster.primary')->operation);
        self::assertStringContainsString('unmanaged', self::action($plan, 'database_cluster.primary')->reason);
        self::assertSame(PlanOperation::NO_CHANGE, self::action($plan, 'database.primary.application')->operation);
        self::assertSame(PlanOperation::NO_CHANGE, self::action($plan, 'database_attachment.production')->operation);
        self::assertSame(1, $cloud->clusterCalls);
        self::assertSame(['cluster-1' => 1], $cloud->databaseCalls);
    }

    public function testImportedDatabaseGraphIsResolvedByOwnedRemoteIdentity(): void
    {
        $plan = self::plan(self::blueprint(), self::matchingCloud(), self::databaseState());

        $cluster = self::action($plan, 'database_cluster.primary');
        $database = self::action($plan, 'database.primary.application');
        self::assertSame(PlanOperation::NO_CHANGE, $cluster->operation);
        self::assertStringContainsString('Owned', $cluster->reason);
        self::assertSame(PlanOperation::NO_CHANGE, $database->operation);
        self::assertStringContainsString('Owned', $database->reason);
        self::assertSame(PlanOperation::NO_CHANGE, self::action($plan, 'database_attachment.production')->operation);
    }

    public function testReleasedLogicalDatabaseUsesUnmanagedPlanningSemantics(): void
    {
        $released = self::databaseState()->withoutResource(
            new ResourceAddress(ResourceType::DATABASE, 'primary.application'),
        );

        $existing = self::action(
            self::plan(self::blueprint(), self::matchingCloud(), $released),
            'database.primary.application',
        );
        self::assertSame(PlanOperation::NO_CHANGE, $existing->operation);
        self::assertStringContainsString('unmanaged', $existing->reason);

        $missing = self::action(
            self::plan(self::blueprint(), self::matchingCloud(databases: []), $released),
            'database.primary.application',
        );
        self::assertSame(PlanOperation::CREATE, $missing->operation);

        $withoutDatabaseBlueprint = self::plan(self::blueprint(database: false), self::matchingCloud(), $released);
        self::assertNull(self::findAction($withoutDatabaseBlueprint, 'database.primary.application'));
    }

    public function testOwnedClusterMissingIdentityAndSameNameReplacementAreUnsupported(): void
    {
        $missing = self::action(self::plan(
            self::blueprint(),
            new DatabasePlanningCloud(clusters: []),
            self::databaseState(),
        ), 'database_cluster.primary');
        self::assertSame(PlanOperation::UNSUPPORTED, $missing->operation);
        self::assertStringContainsString('missing', $missing->reason);

        $replacement = self::action(self::plan(
            self::blueprint(),
            self::matchingCloud(self::mysqlCluster(id: 'replacement-cluster')),
            self::databaseState(),
        ), 'database_cluster.primary');
        self::assertSame(PlanOperation::UNSUPPORTED, $replacement->operation);
        self::assertStringContainsString('replacement', $replacement->reason);
    }

    public function testOwnedLogicalDatabaseMissingIdentityAndSameNameReplacementAreUnsupported(): void
    {
        $missing = self::action(self::plan(
            self::blueprint(),
            self::matchingCloud(databases: []),
            self::databaseState(),
        ), 'database.primary.application');
        self::assertSame(PlanOperation::UNSUPPORTED, $missing->operation);
        self::assertStringContainsString('missing', $missing->reason);

        $replacement = self::action(self::plan(
            self::blueprint(),
            self::matchingCloud(databases: [new CloudDatabase('replacement-database', 'cluster-1', 'application')]),
            self::databaseState(),
        ), 'database.primary.application');
        self::assertSame(PlanOperation::UNSUPPORTED, $replacement->operation);
        self::assertStringContainsString('replacement', $replacement->reason);
    }

    public function testImportedClusterConfigurationDifferenceRemainsReadOnlyUnsupported(): void
    {
        $cloud = self::matchingCloud(self::mysqlCluster(configuration: new CloudLaravelMySqlConfiguration(
            'different-size', 5, 1, false, false,
        )));
        $action = self::action(self::plan(self::blueprint(), $cloud, self::databaseState()), 'database_cluster.primary');

        self::assertSame(PlanOperation::UNSUPPORTED, $action->operation);
        self::assertSame('size', $action->changes[0]->field);
    }

    public function testOwnedDatabaseResourcesAbsentFromBlueprintRemainVisibleAndNonDestructive(): void
    {
        $plan = self::plan(self::blueprint(database: false), self::matchingCloud(), self::databaseState());

        self::assertSame(PlanOperation::UNSUPPORTED, self::action($plan, 'database_cluster.primary')->operation);
        self::assertSame(PlanOperation::UNSUPPORTED, self::action($plan, 'database.primary.application')->operation);
        self::assertNull(self::findAction($plan, 'database_attachment.production'));
    }

    /** @return iterable<string, array{CloudDatabaseCluster, string}> */
    public static function unsupportedClusterDifferences(): iterable
    {
        yield 'type' => [self::mysqlCluster(type: 'neon_serverless_postgres_18'), 'type'];
        yield 'region' => [self::mysqlCluster(region: 'us-east-1'), 'region'];
        yield 'MySQL config' => [self::mysqlCluster(configuration: new CloudLaravelMySqlConfiguration(
            'db-flex.m-1vcpu-1gb', 5, 1, false, false,
        )), 'size'];
        yield 'unknown config' => [self::mysqlCluster(configuration: new CloudUnknownDatabaseConfiguration()), 'configuration'];
    }

    #[DataProvider('unsupportedClusterDifferences')]
    public function testClusterDifferencesAreUnsupportedAndChildrenAreNotFetched(
        CloudDatabaseCluster $cluster,
        string $expected,
    ): void {
        $cloud = new DatabasePlanningCloud(clusters: [$cluster]);
        $action = self::action(self::plan(self::blueprint(), $cloud), 'database_cluster.primary');

        self::assertSame(PlanOperation::UNSUPPORTED, $action->operation);
        self::assertStringContainsString($expected, serialize([$action->reason, $action->changes]));
        self::assertSame(0, $cloud->databaseCalls['cluster-1'] ?? 0);
    }

    public function testExactNeonConfigurationMatchesAndDifferenceIsSafe(): void
    {
        $desired = self::blueprint(neon: true);
        $matching = self::matchingCloud(self::neonCluster());
        self::assertSame(
            PlanOperation::NO_CHANGE,
            self::action(self::plan($desired, $matching), 'database_cluster.primary')->operation,
        );

        $different = self::matchingCloud(self::neonCluster(new CloudNeonPostgresConfiguration(0.25, 2, 300, 7)));
        $action = self::action(self::plan($desired, $different), 'database_cluster.primary');
        self::assertSame(PlanOperation::UNSUPPORTED, $action->operation);
        self::assertSame('cu_max', $action->changes[0]->field);
        self::assertSame('2', $action->changes[0]->before);
        self::assertSame('1', $action->changes[0]->after);
    }

    /** @return iterable<string, array{string, string}> */
    public static function unsafeStatuses(): iterable
    {
        yield 'creating' => ['creating', 'transitional'];
        yield 'updating' => ['updating', 'transitional'];
        yield 'restoring' => ['restoring', 'transitional'];
        yield 'archiving' => ['archiving', 'transitional'];
        yield 'restore failed' => ['restore_failed', 'usable'];
        yield 'disabled' => ['disabled', 'usable'];
        yield 'official unknown' => ['unknown', 'usable'];
        yield 'unrecognized' => ['future-status', 'unknown'];
    }

    #[DataProvider('unsafeStatuses')]
    public function testUnsafeStatusesAreUnsupported(string $status, string $reasonFragment): void
    {
        $action = self::action(self::plan(
            self::blueprint(),
            self::matchingCloud(self::mysqlCluster(status: $status)),
        ), 'database_cluster.primary');

        self::assertSame(PlanOperation::UNSUPPORTED, $action->operation);
        self::assertStringContainsString($reasonFragment, $action->reason);
    }

    public function testDuplicateClusterNamesAreAmbiguousWithoutExposingIds(): void
    {
        $cloud = new DatabasePlanningCloud(clusters: [
            self::mysqlCluster(id: 'secret-cluster-a'),
            self::mysqlCluster(id: 'secret-cluster-b'),
        ]);
        $action = self::action(self::plan(self::blueprint(), $cloud), 'database_cluster.primary');

        self::assertSame(PlanOperation::UNSUPPORTED, $action->operation);
        self::assertStringContainsString('ambiguous', $action->reason);
        self::assertStringNotContainsString('secret-cluster', serialize($action));
    }

    public function testMissingAndDuplicateLogicalDatabasesAreUnsupported(): void
    {
        $missing = self::matchingCloud(databases: []);
        self::assertSame(
            PlanOperation::UNSUPPORTED,
            self::action(self::plan(self::blueprint(), $missing), 'database.primary.application')->operation,
        );

        $duplicate = self::matchingCloud(databases: [
            new CloudDatabase('database-a', 'cluster-1', 'application'),
            new CloudDatabase('database-b', 'cluster-1', 'application'),
        ]);
        $action = self::action(self::plan(self::blueprint(), $duplicate), 'database.primary.application');
        self::assertSame(PlanOperation::UNSUPPORTED, $action->operation);
        self::assertStringContainsString('ambiguous', $action->reason);
    }

    public function testMissingLogicalDatabaseUnderOwnedClusterIsCreate(): void
    {
        $cluster = new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary');
        $state = new StateDocument(
            StateVersion::V1,
            1,
            'acme',
            new StateResource($cluster, ResourceType::DATABASE_CLUSTER, 'cluster-1'),
        );
        $action = self::action(self::plan(
            self::blueprint(),
            self::matchingCloud(databases: []),
            $state,
        ), 'database.primary.application');

        self::assertSame(PlanOperation::CREATE, $action->operation);
        self::assertStringContainsString('owned parent', $action->reason);
    }

    public function testSameDatabaseNameInUnrelatedClusterIsIgnored(): void
    {
        $cloud = self::matchingCloud();
        $cloud->databases['other-cluster'] = [new CloudDatabase('wrong-id', 'other-cluster', 'application')];

        $plan = self::plan(self::blueprint(), $cloud);

        self::assertSame(PlanOperation::NO_CHANGE, self::action($plan, 'database.primary.application')->operation);
        self::assertArrayNotHasKey('other-cluster', $cloud->databaseCalls);
    }

    /** @return iterable<string, array{?string, string}> */
    public static function attachmentDifferences(): iterable
    {
        yield 'missing' => [null, 'no Database attachment'];
        yield 'different' => ['other-database', 'differs'];
    }

    #[DataProvider('attachmentDifferences')]
    public function testAttachmentDifferencesAreUnsupported(?string $databaseId, string $reason): void
    {
        $cloud = self::matchingCloud();
        $cloud->environmentDatabaseId = $databaseId;

        $action = self::action(self::plan(self::blueprint(), $cloud), 'database_attachment.production');

        self::assertSame(PlanOperation::UNSUPPORTED, $action->operation);
        self::assertStringContainsString($reason, $action->reason);
    }

    public function testOmittedDesiredAttachmentDoesNotInspectOrDetachExternalAttachment(): void
    {
        $cloud = self::matchingCloud();
        $cloud->environmentDatabaseId = 'external-database';
        $plan = self::plan(self::blueprint(attachment: false), $cloud);

        self::assertNull(self::findAction($plan, 'database_attachment.production'));
        self::assertSame(1, $cloud->environmentCalls);
    }

    public function testPlanOrderingKeepsParentsAndAttachmentBeforeVariables(): void
    {
        $addresses = array_map(
            static fn (PlanAction $action): string => (string) $action->address,
            iterator_to_array(self::plan(self::blueprint(), self::matchingCloud()), false),
        );

        self::assertSame([
            'application.my-api',
            'environment.production',
            'database_cluster.primary',
            'database.primary.application',
            'database_attachment.production',
        ], $addresses);
    }

    public function testDatabaseActionsContainNoRemoteIdsOrCredentials(): void
    {
        $plan = self::plan(self::blueprint(), self::matchingCloud());
        $databaseActions = array_values(array_filter(
            iterator_to_array($plan, false),
            static fn (PlanAction $action): bool => in_array($action->resourceType, [
                ResourceType::DATABASE_CLUSTER,
                ResourceType::DATABASE,
                ResourceType::DATABASE_ATTACHMENT,
            ], true),
        ));

        self::assertStringNotContainsString('cluster-1', serialize($databaseActions));
        self::assertStringNotContainsString('database-1', serialize($databaseActions));
        self::assertStringNotContainsString('password', serialize($databaseActions));
    }

    public function testApplyRefusesActionableDatabaseAttachmentOperation(): void
    {
        $plan = new ExecutionPlan(new PlanAction(
            new ResourceAddress(ResourceType::DATABASE_ATTACHMENT, 'test'),
            ResourceType::DATABASE_ATTACHMENT,
            PlanOperation::CREATE,
            'Must remain read-only.',
        ));

        $this->expectException(ApplyRefusedException::class);
        self::apply()->assertSupported($plan);
    }

    public function testApplyAcceptsDatabaseNoChangeAlongsideExistingSafeEnvironmentUpdate(): void
    {
        $plan = new ExecutionPlan(
            new PlanAction(
                new ResourceAddress(ResourceType::ENVIRONMENT, 'production'),
                ResourceType::ENVIRONMENT,
                PlanOperation::UPDATE,
                'Branch differs.',
                'env-1',
                new PlanChange('branch', 'old', 'main'),
            ),
            new PlanAction(
                new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary'),
                ResourceType::DATABASE_CLUSTER,
                PlanOperation::NO_CHANGE,
                'Read-only match.',
            ),
        );

        self::apply()->assertSupported($plan);
        self::addToAssertionCount(1);
    }

    /** @return iterable<string, array{PlanAction, ResourceType}> */
    public static function existingMutationActions(): iterable
    {
        yield 'Application CREATE' => [new PlanAction(
            new ResourceAddress(ResourceType::APPLICATION, 'my-api'),
            ResourceType::APPLICATION,
            PlanOperation::CREATE,
            'Application does not exist.',
        ), ResourceType::DATABASE_CLUSTER];
        yield 'Environment UPDATE' => [new PlanAction(
            new ResourceAddress(ResourceType::ENVIRONMENT, 'production'),
            ResourceType::ENVIRONMENT,
            PlanOperation::UPDATE,
            'Branch differs.',
            'env-1',
            new PlanChange('branch', 'old', 'main'),
        ), ResourceType::DATABASE_CLUSTER];
        yield 'Variable UPDATE' => [new PlanAction(
            new ResourceAddress(ResourceType::VARIABLE, 'production.APP_ENV'),
            ResourceType::VARIABLE,
            PlanOperation::UPDATE,
            'Variable differs.',
        ), ResourceType::DATABASE_ATTACHMENT];
    }

    #[DataProvider('existingMutationActions')]
    public function testUnsupportedDatabaseActionRefusesMixedPlanBeforeMutationOrState(
        PlanAction $existingMutation,
        ResourceType $databaseType,
    ): void {
        $plan = new ExecutionPlan(
            $existingMutation,
            new PlanAction(
                new ResourceAddress($databaseType, 'blocked'),
                $databaseType,
                PlanOperation::UNSUPPORTED,
                'Database mutation is not supported.',
            ),
        );
        $states = new NeverStartedStateStore();

        try {
            self::apply()->execute(self::blueprint(), $plan, self::matchingCloud(), $states);
            self::fail('Expected apply preflight refusal.');
        } catch (ApplyRefusedException) {
            self::assertSame(0, $states->beginCalls);
        }
    }

    private static function plan(
        Blueprint $blueprint,
        DatabasePlanningCloud $cloud,
        ?StateDocument $state = null,
    ): ExecutionPlan
    {
        return (new CreatePlan(new VariableValueResolver(new EmptyEnvironmentValues())))
            ->create($blueprint, $cloud, $state ?? StateDocument::empty());
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

    private static function apply(): CreateOnlyApply
    {
        return new CreateOnlyApply(new VariableValueResolver(new EmptyEnvironmentValues()));
    }

    private static function action(ExecutionPlan $plan, string $address): PlanAction
    {
        return self::findAction($plan, $address)
            ?? throw new \LogicException('Missing action ' . $address);
    }

    private static function findAction(ExecutionPlan $plan, string $address): ?PlanAction
    {
        foreach ($plan as $action) {
            if ((string) $action->address === $address) {
                return $action;
            }
        }
        return null;
    }

    private static function blueprint(
        bool $database = true,
        bool $attachment = true,
        bool $neon = false,
    ): Blueprint {
        $configuration = $neon
            ? new NeonPostgresConfiguration(0.25, 1, 300, 7)
            : new LaravelMySqlConfiguration('db-flex.m-1vcpu-512mb', 5, 1, false, false);
        $clusters = $database ? new DatabaseClusterDefinitionCollection(new DatabaseClusterDefinition(
            'primary',
            $neon ? DatabaseClusterType::NEON_SERVERLESS_POSTGRES_18 : DatabaseClusterType::LARAVEL_MYSQL_8,
            'eu-central-1',
            $configuration,
            new LogicalDatabaseDefinitionCollection(new LogicalDatabaseDefinition('application')),
        )) : new DatabaseClusterDefinitionCollection();

        return new Blueprint(
            BlueprintSchemaVersion::V1,
            'acme',
            new ApplicationDefinition(
                'my-api',
                'eu-central-1',
                new SourceDefinition(SourceProvider::GITHUB, 'acme/my-api'),
            ),
            new EnvironmentDefinitionCollection(new EnvironmentDefinition(
                'production',
                'main',
                new VariableDefinitionCollection(),
                $database && $attachment ? new DatabaseReference('primary', 'application') : null,
            )),
            $clusters,
        );
    }

    /** @param list<CloudDatabase>|null $databases */
    private static function matchingCloud(
        ?CloudDatabaseCluster $cluster = null,
        ?array $databases = null,
    ): DatabasePlanningCloud {
        return new DatabasePlanningCloud(
            clusters: [$cluster ?? self::mysqlCluster()],
            databases: ['cluster-1' => $databases ?? [new CloudDatabase('database-1', 'cluster-1', 'application')]],
        );
    }

    private static function mysqlCluster(
        string $id = 'cluster-1',
        string $type = 'laravel_mysql_8',
        string $status = 'available',
        string $region = 'eu-central-1',
        ?\LaravelCloudBlueprint\Cloud\DTO\CloudDatabaseClusterConfiguration $configuration = null,
    ): CloudDatabaseCluster {
        return new CloudDatabaseCluster(
            $id,
            'primary',
            $type,
            $status,
            $region,
            $configuration ?? new CloudLaravelMySqlConfiguration('db-flex.m-1vcpu-512mb', 5, 1, false, false),
        );
    }

    private static function neonCluster(?CloudNeonPostgresConfiguration $configuration = null): CloudDatabaseCluster
    {
        return new CloudDatabaseCluster(
            'cluster-1',
            'primary',
            'neon_serverless_postgres_18',
            'available',
            'eu-central-1',
            $configuration ?? new CloudNeonPostgresConfiguration(0.25, 1, 300, 7),
        );
    }
}

final class EmptyEnvironmentValues implements \LaravelCloudBlueprint\Planning\Contract\EnvironmentValueProvider
{
    public function value(string $name): ?string
    {
        return null;
    }
}

final class DatabasePlanningCloud implements LaravelCloudDatabaseClient
{
    public int $clusterCalls = 0;
    public int $environmentCalls = 0;

    /** @var array<string, int> */
    public array $databaseCalls = [];

    public ?string $environmentDatabaseId = 'database-1';

    /**
     * @param list<CloudDatabaseCluster> $clusters
     * @param array<string, list<CloudDatabase>> $databases
     */
    public function __construct(
        private readonly array $clusters = [],
        public array $databases = [],
    ) {
    }

    public function organization(): CloudOrganization
    {
        return new CloudOrganization('org-1', 'Acme', 'acme');
    }

    public function applications(): array
    {
        return [new CloudApplication('app-1', 'my-api', 'my-api', 'eu-central-1', 'acme/my-api')];
    }

    public function environments(string $applicationId): array
    {
        ++$this->environmentCalls;
        return [new CloudEnvironment('env-1', $applicationId, 'production', 'main', $this->environmentDatabaseId)];
    }

    public function environment(string $environmentId): CloudEnvironmentDetails
    {
        return new CloudEnvironmentDetails($environmentId, 'production', new CloudEnvironmentVariableCollection(), $this->environmentDatabaseId);
    }

    public function databaseClusters(): array
    {
        ++$this->clusterCalls;
        return $this->clusters;
    }

    public function databaseCluster(string $clusterId): CloudDatabaseCluster
    {
        throw new \LogicException('Unexpected Cluster detail request.');
    }

    public function databases(string $clusterId): array
    {
        $this->databaseCalls[$clusterId] = ($this->databaseCalls[$clusterId] ?? 0) + 1;
        return $this->databases[$clusterId] ?? [];
    }

    public function database(string $clusterId, string $databaseId): CloudDatabase
    {
        throw new \LogicException('Unexpected Database detail request.');
    }

    public function createApplication(CreateApplicationRequest $request): CloudApplication
    {
        throw new \LogicException('Unexpected mutation.');
    }

    public function createEnvironment(string $applicationId, CreateEnvironmentRequest $request): CloudEnvironment
    {
        throw new \LogicException('Unexpected mutation.');
    }

    public function updateEnvironment(string $environmentId, UpdateEnvironmentRequest $request): UpdatedCloudEnvironment
    {
        throw new \LogicException('Unexpected mutation.');
    }

    public function setEnvironmentVariables(string $environmentId, SetEnvironmentVariablesRequest $request): void
    {
        throw new \LogicException('Unexpected mutation.');
    }
}

final class NeverStartedStateStore implements StateStore
{
    public int $beginCalls = 0;

    public function load(): StateDocument
    {
        return StateDocument::empty();
    }

    public function save(StateDocument $state): StateDocument
    {
        throw new \LogicException('Apply must not save state.');
    }

    public function begin(): StateTransaction
    {
        ++$this->beginCalls;
        throw new \LogicException('Apply must not begin a state transaction.');
    }
}
