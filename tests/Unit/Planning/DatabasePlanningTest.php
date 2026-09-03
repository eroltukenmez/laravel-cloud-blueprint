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
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudDatabaseLifecycleClient;
use LaravelCloudBlueprint\Cloud\DTO\CloudApplication;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabase;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabaseCluster;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabaseSnapshot;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironment;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironmentDetails;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironmentVariableCollection;
use LaravelCloudBlueprint\Cloud\DTO\CloudLaravelMySqlConfiguration;
use LaravelCloudBlueprint\Cloud\DTO\CloudNeonPostgresConfiguration;
use LaravelCloudBlueprint\Cloud\DTO\CloudOrganization;
use LaravelCloudBlueprint\Cloud\DTO\CloudUnknownDatabaseConfiguration;
use LaravelCloudBlueprint\Cloud\DTO\DatabaseDependencyType;
use LaravelCloudBlueprint\Cloud\DTO\DatabaseDependencies;
use LaravelCloudBlueprint\Cloud\DTO\DatabaseDestructiveReadiness;
use LaravelCloudBlueprint\Cloud\DTO\DatabaseSnapshotStatus;
use LaravelCloudBlueprint\Cloud\DTO\DatabaseSnapshotType;
use LaravelCloudBlueprint\Cloud\DTO\CreateApplicationRequest;
use LaravelCloudBlueprint\Cloud\DTO\CreateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\DTO\SetEnvironmentVariablesRequest;
use LaravelCloudBlueprint\Cloud\DTO\UpdateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\DTO\UpdatedCloudEnvironment;
use LaravelCloudBlueprint\Cloud\Exception\CloudResponseException;
use LaravelCloudBlueprint\Planning\CreatePlan;
use LaravelCloudBlueprint\Planning\ExecutionPlan;
use LaravelCloudBlueprint\Planning\PlanAction;
use LaravelCloudBlueprint\Planning\PlanChange;
use LaravelCloudBlueprint\Planning\PlanOperation;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\Planning\VariableValueResolver;
use LaravelCloudBlueprint\State\StateDocument;
use LaravelCloudBlueprint\State\StateOwnershipClassification;
use LaravelCloudBlueprint\State\StateProvenance;
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

    public function testOwnedDatabaseResourcesAbsentFromBlueprintArePlannedChildFirstAndClusterRemainsNonExecutable(): void
    {
        $plan = self::plan(self::blueprint(database: false), self::matchingCloud(), self::databaseState());

        $actions = iterator_to_array($plan, false);
        self::assertSame(PlanOperation::DELETE, self::action($plan, 'database_cluster.primary')->operation);
        self::assertSame(PlanOperation::DELETE, self::action($plan, 'database.primary.application')->operation);
        self::assertSame('database.primary.application', (string) $actions[0]->address);
        self::assertSame('database_cluster.primary', (string) $actions[1]->address);
        self::assertSame('database_cluster.primary', (string) $actions[0]->parent);
        self::assertStringNotContainsString('not supported', $actions[0]->reason);
        self::assertStringContainsString('execution remains unsupported', $actions[1]->reason);
        self::assertNull(self::findAction($plan, 'database_attachment.production'));
    }

    public function testLogicalDatabaseDeleteReadinessRequiresCompleteEmptyAttachmentsAndMatchingParent(): void
    {
        $safeDatabase = new CloudDatabase(
            'database-1', 'cluster-1', 'application', 'cluster-1', [], true,
        );
        $safeCloud = self::matchingCloud(databases: [$safeDatabase]);
        $safe = self::action(self::plan(
            self::blueprint(database: false),
            $safeCloud,
            self::databaseState(),
        ), 'database.primary.application');
        self::assertSame(DatabaseDestructiveReadiness::SAFE, $safe->databaseDependencies?->readiness());
        self::assertStringContainsString('eligible for guarded deletion', $safe->reason);
        self::assertStringNotContainsString('not supported', $safe->reason);
        self::assertSame([['cluster-1', 'database-1']], $safeCloud->destructiveDatabaseCalls);

        $attachedDatabase = new CloudDatabase(
            'database-1', 'cluster-1', 'application', 'cluster-1', ['env-1', 'env-2'], true,
        );
        $attached = self::action(self::plan(
            self::blueprint(database: false),
            self::matchingCloud(databases: [$attachedDatabase]),
            self::databaseState(),
        ), 'database.primary.application');
        $dependencies = $attached->databaseDependencies;
        self::assertNotNull($dependencies);
        self::assertSame(DatabaseDestructiveReadiness::BLOCKED, $dependencies->readiness());
        self::assertSame(
            [DatabaseDependencyType::ENVIRONMENT_ATTACHMENT],
            $dependencies->blockingCategories(),
        );
        self::assertSame(2, $dependencies->environmentAttachmentCount);
        self::assertStringContainsString('guarded deletion is blocked', $attached->reason);
        self::assertStringNotContainsString('not supported', $attached->reason);
    }

    public function testLogicalDatabaseMissingOrMalformedDiscoveryIsUnknown(): void
    {
        foreach ([
            new CloudDatabase('database-1', 'cluster-1', 'application'),
            new CloudDatabase('database-1', 'cluster-1', 'application', 'cluster-1', [], false, [], ['environments']),
        ] as $database) {
            $action = self::action(self::plan(
                self::blueprint(database: false),
                self::matchingCloud(databases: [$database]),
                self::databaseState(),
            ), 'database.primary.application');
            self::assertSame(DatabaseDestructiveReadiness::UNKNOWN, $action->databaseDependencies?->readiness());
            self::assertStringContainsString('readiness is unknown', $action->reason);
            self::assertStringNotContainsString('not supported', $action->reason);
        }
    }

    public function testLogicalDatabaseParentMismatchIsBlockedAsOwnershipConflict(): void
    {
        $database = new CloudDatabase(
            'database-1', 'cluster-1', 'application', 'different-cluster', [], true,
        );
        $action = self::action(self::plan(
            self::blueprint(database: false),
            self::matchingCloud(databases: [$database]),
            self::databaseState(),
        ), 'database.primary.application');

        self::assertSame(DatabaseDestructiveReadiness::BLOCKED, $action->databaseDependencies?->readiness());
        self::assertContains(
            DatabaseDependencyType::OWNERSHIP_CONFLICT,
            $action->databaseDependencies->blockingCategories(),
        );
    }

    public function testClusterReadinessClassifiesOwnedAndUnmanagedChildrenConservatively(): void
    {
        $clusterAddress = new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary');
        $clusterOnlyState = new StateDocument(
            StateVersion::V1,
            0,
            null,
            new StateResource($clusterAddress, ResourceType::DATABASE_CLUSTER, 'cluster-1'),
        );

        $safeCluster = self::mysqlCluster(
            configuration: new CloudLaravelMySqlConfiguration('db-flex.m-1vcpu-512mb', 5, 0, false, false),
            databaseIds: [],
            childDiscoveryComplete: true,
        );
        $safe = self::action(self::plan(
            self::blueprint(database: false),
            self::matchingCloud($safeCluster),
            $clusterOnlyState,
        ), 'database_cluster.primary');
        self::assertSame(DatabaseDestructiveReadiness::SAFE, $safe->databaseDependencies?->readiness());

        $ownedCluster = self::mysqlCluster(databaseIds: ['database-1'], childDiscoveryComplete: true);
        $owned = self::action(self::plan(
            self::blueprint(database: false),
            self::matchingCloud($ownedCluster),
            self::databaseState(),
        ), 'database_cluster.primary');
        self::assertSame(DatabaseDestructiveReadiness::BLOCKED, $owned->databaseDependencies?->readiness());
        self::assertContains(DatabaseDependencyType::OWNED_DATABASE_CHILD, $owned->databaseDependencies->categories());

        $unmanagedCluster = self::mysqlCluster(databaseIds: ['unmanaged-id'], childDiscoveryComplete: true);
        $unmanagedPlan = self::plan(
            self::blueprint(database: false),
            self::matchingCloud($unmanagedCluster, [new CloudDatabase('unmanaged-id', 'cluster-1', 'reporting')]),
            $clusterOnlyState,
        );
        $unmanaged = self::action($unmanagedPlan, 'database_cluster.primary');
        self::assertSame(DatabaseDestructiveReadiness::BLOCKED, $unmanaged->databaseDependencies?->readiness());
        self::assertContains(DatabaseDependencyType::UNMANAGED_DATABASE_CHILD, $unmanaged->databaseDependencies->categories());
        self::assertNull(self::findAction($unmanagedPlan, 'database.primary.reporting'));
    }

    public function testDerivedDatabaseCannotEnterOrdinaryBlueprintOmissionDeletePath(): void
    {
        $clusterAddress = new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary');
        $derivedAddress = new ResourceAddress(ResourceType::DATABASE, 'primary.__derived_default');
        $state = new StateDocument(
            StateVersion::V2,
            0,
            'acme',
            new StateResource($clusterAddress, ResourceType::DATABASE_CLUSTER, 'cluster-1'),
            new StateResource(
                $derivedAddress,
                ResourceType::DATABASE,
                'database-derived',
                $clusterAddress,
                StateOwnershipClassification::DERIVED,
                StateProvenance::CLUSTER_CREATE_RESPONSE,
            ),
        );
        $cloud = self::matchingCloud(databases: [
            new CloudDatabase('database-derived', 'cluster-1', 'any-cloud-name'),
        ]);

        $action = self::action(
            self::plan(self::blueprint(database: false), $cloud, $state),
            'database.primary.__derived_default',
        );

        self::assertSame(PlanOperation::UNSUPPORTED, $action->operation);
        self::assertStringContainsString('derived logical Database', $action->reason);
        self::assertNull($action->databaseDependencies);
        self::assertSame([], $cloud->destructiveDatabaseCalls);
    }

    public function testClusterSnapshotsAndRetainedRecoveryBlockReadinessWithoutExposingSnapshotIdentity(): void
    {
        $cluster = self::mysqlCluster(databaseIds: [], childDiscoveryComplete: true);
        $cloud = self::matchingCloud($cluster);
        $cloud->snapshots['cluster-1'] = [
            new CloudDatabaseSnapshot(
                'secret-snapshot-id',
                'cluster-1',
                DatabaseSnapshotType::MANUAL,
                DatabaseSnapshotStatus::AVAILABLE,
                false,
            ),
            new CloudDatabaseSnapshot(
                'scheduled-snapshot-id',
                'cluster-1',
                DatabaseSnapshotType::SCHEDULED,
                DatabaseSnapshotStatus::PENDING,
                true,
            ),
            new CloudDatabaseSnapshot(
                'unknown-status-snapshot-id',
                'cluster-1',
                DatabaseSnapshotType::MANUAL,
                null,
                false,
            ),
        ];
        $state = new StateDocument(StateVersion::V1, 0, null,
            new StateResource(new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary'),
                ResourceType::DATABASE_CLUSTER, 'cluster-1'));

        $action = self::action(self::plan(self::blueprint(database: false), $cloud, $state), 'database_cluster.primary');

        self::assertNotNull($action->databaseDependencies);
        self::assertSame(DatabaseDestructiveReadiness::BLOCKED, $action->databaseDependencies->readiness());
        self::assertContains(DatabaseDependencyType::DATABASE_SNAPSHOT, $action->databaseDependencies->categories());
        self::assertContains(DatabaseDependencyType::RETAINED_DATABASE_RECOVERY, $action->databaseDependencies->categories());
        self::assertContains('snapshot_status', $action->databaseDependencies->unknownRelationships);
        self::assertTrue($action->databaseDependencies->snapshotDiscoveryComplete);
        self::assertSame(3, $action->databaseDependencies->snapshotCount);
        self::assertSame(2, $action->databaseDependencies->manualSnapshotCount);
        self::assertSame(1, $action->databaseDependencies->scheduledSnapshotCount);
        self::assertSame(['cluster-1'], $cloud->snapshotCalls);
        self::assertStringNotContainsString('secret-snapshot-id', $action->reason);
        self::assertStringNotContainsString('scheduled-snapshot-id', $action->reason);
    }

    public function testClusterSnapshotFailureUnknownLifecycleAndTransitionalLifecycleFailClosed(): void
    {
        $configuration = new CloudLaravelMySqlConfiguration('db-flex.m-1vcpu-512mb', 5, 0, false, false);
        $state = new StateDocument(StateVersion::V1, 0, null,
            new StateResource(new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary'),
                ResourceType::DATABASE_CLUSTER, 'cluster-1'));

        $failed = self::matchingCloud(self::mysqlCluster(configuration: $configuration, databaseIds: [], childDiscoveryComplete: true));
        $failed->failSnapshots = true;
        $unknownAction = self::action(self::plan(self::blueprint(database: false), $failed, $state), 'database_cluster.primary');
        self::assertNotNull($unknownAction->databaseDependencies);
        self::assertSame(DatabaseDestructiveReadiness::UNKNOWN, $unknownAction->databaseDependencies->readiness());
        self::assertContains('snapshots', $unknownAction->databaseDependencies->missingRelationships);
        self::assertFalse($unknownAction->databaseDependencies->snapshotDiscoveryComplete);

        $unknownStatus = self::action(self::plan(
            self::blueprint(database: false),
            self::matchingCloud(self::mysqlCluster(status: 'future-status', configuration: $configuration,
                databaseIds: [], childDiscoveryComplete: true)),
            $state,
        ), 'database_cluster.primary');
        self::assertNotNull($unknownStatus->databaseDependencies);
        self::assertSame(DatabaseDestructiveReadiness::UNKNOWN, $unknownStatus->databaseDependencies->readiness());
        self::assertContains('cluster_lifecycle', $unknownStatus->databaseDependencies->unknownRelationships);

        $creating = self::action(self::plan(
            self::blueprint(database: false),
            self::matchingCloud(self::mysqlCluster(status: 'creating', configuration: $configuration,
                databaseIds: [], childDiscoveryComplete: true)),
            $state,
        ), 'database_cluster.primary');
        self::assertNotNull($creating->databaseDependencies);
        self::assertSame(DatabaseDestructiveReadiness::BLOCKED, $creating->databaseDependencies->readiness());
        self::assertContains(DatabaseDependencyType::DATABASE_CLUSTER_LIFECYCLE,
            $creating->databaseDependencies->blockingCategories());

        $unknownRecovery = self::action(self::plan(
            self::blueprint(database: false),
            self::matchingCloud(self::mysqlCluster(configuration: new CloudUnknownDatabaseConfiguration(),
                databaseIds: [], childDiscoveryComplete: true)),
            $state,
        ), 'database_cluster.primary');
        self::assertNotNull($unknownRecovery->databaseDependencies);
        self::assertSame(DatabaseDestructiveReadiness::UNKNOWN, $unknownRecovery->databaseDependencies->readiness());
        self::assertContains('retained_recovery', $unknownRecovery->databaseDependencies->unknownRelationships);
        self::assertFalse($unknownRecovery->databaseDependencies->recoveryEvidenceComplete);
    }

    public function testIncompleteClusterChildDiscoveryIsUnknown(): void
    {
        $cluster = self::mysqlCluster(
            configuration: new CloudLaravelMySqlConfiguration('db-flex.m-1vcpu-512mb', 5, 0, false, false),
        );
        $clusterAddress = new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary');
        $state = new StateDocument(StateVersion::V1, 0, null,
            new StateResource($clusterAddress, ResourceType::DATABASE_CLUSTER, 'cluster-1'));
        $action = self::action(self::plan(
            self::blueprint(database: false),
            self::matchingCloud($cluster),
            $state,
        ), 'database_cluster.primary');

        self::assertSame(DatabaseDestructiveReadiness::UNKNOWN, $action->databaseDependencies?->readiness());
    }

    public function testClusterReadinessBlocksMultipleOwnedAndMixedUnmanagedChildren(): void
    {
        $clusterAddress = new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary');
        $state = new StateDocument(
            StateVersion::V1,
            0,
            null,
            new StateResource($clusterAddress, ResourceType::DATABASE_CLUSTER, 'cluster-1'),
            new StateResource(new ResourceAddress(ResourceType::DATABASE, 'primary.application'),
                ResourceType::DATABASE, 'database-1', $clusterAddress),
            new StateResource(new ResourceAddress(ResourceType::DATABASE, 'primary.reporting'),
                ResourceType::DATABASE, 'database-2', $clusterAddress),
        );
        $cluster = self::mysqlCluster(
            configuration: new CloudLaravelMySqlConfiguration('db-flex.m-1vcpu-512mb', 5, 0, false, false),
            databaseIds: ['database-1', 'database-2', 'unmanaged-id'],
            childDiscoveryComplete: true,
        );
        $cloud = self::matchingCloud($cluster, [
            new CloudDatabase('database-1', 'cluster-1', 'application', 'cluster-1', [], true),
            new CloudDatabase('database-2', 'cluster-1', 'reporting', 'cluster-1', [], true),
            new CloudDatabase('unmanaged-id', 'cluster-1', 'external'),
        ]);
        $cloud->snapshots['cluster-1'] = [new CloudDatabaseSnapshot(
            'snapshot-1', 'cluster-1', DatabaseSnapshotType::MANUAL, DatabaseSnapshotStatus::AVAILABLE, false,
        )];

        $plan = self::plan(self::blueprint(database: false), $cloud, $state);
        $action = self::action($plan, 'database_cluster.primary');
        $dependencies = $action->databaseDependencies;
        self::assertNotNull($dependencies);
        self::assertSame(DatabaseDestructiveReadiness::BLOCKED, $dependencies->readiness());
        self::assertSame(2, $dependencies->ownedChildCount);
        self::assertSame(1, $dependencies->unmanagedChildCount);
        self::assertContains(DatabaseDependencyType::DATABASE_SNAPSHOT, $dependencies->blockingCategories());
        self::assertSame([
            'database.primary.application',
            'database.primary.reporting',
            'database_cluster.primary',
        ], array_slice(array_map(
            static fn (PlanAction $item): string => (string) $item->address,
            iterator_to_array($plan, false),
        ), 0, 3));
        self::assertNull(self::findAction($plan, 'database.primary.external'));
    }

    public function testClusterLifecycleChangeAcrossDiscoveryObservationsCannotStaySafe(): void
    {
        $configuration = new CloudLaravelMySqlConfiguration('db-flex.m-1vcpu-512mb', 5, 0, false, false);
        $state = new StateDocument(StateVersion::V1, 0, null,
            new StateResource(new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary'),
                ResourceType::DATABASE_CLUSTER, 'cluster-1'));

        $readiness = [];
        foreach (['available', 'creating', 'future-status'] as $status) {
            $action = self::action(self::plan(
                self::blueprint(database: false),
                self::matchingCloud(self::mysqlCluster(status: $status, configuration: $configuration,
                    databaseIds: [], childDiscoveryComplete: true)),
                $state,
            ), 'database_cluster.primary');
            self::assertNotNull($action->databaseDependencies);
            $readiness[] = $action->databaseDependencies->readiness();
        }

        self::assertSame([
            DatabaseDestructiveReadiness::SAFE,
            DatabaseDestructiveReadiness::BLOCKED,
            DatabaseDestructiveReadiness::UNKNOWN,
        ], $readiness);
    }

    public function testLogicalDatabaseDeleteUsesExactStateIdentityNotSameNameReplacement(): void
    {
        $owned = new CloudDatabase('database-1', 'cluster-1', 'application', 'cluster-1', [], true);
        $replacement = new CloudDatabase('replacement-id', 'cluster-1', 'application', 'cluster-1', ['env-x'], true);
        $action = self::action(self::plan(
            self::blueprint(database: false),
            self::matchingCloud(databases: [$replacement, $owned]),
            self::databaseState(),
        ), 'database.primary.application');

        self::assertSame('database-1', $action->remoteId);
        self::assertSame(DatabaseDestructiveReadiness::SAFE, $action->databaseDependencies?->readiness());
    }

    public function testExactDatabaseAndClusterRediscoveryFailuresProduceUnknownReadiness(): void
    {
        $databaseFailure = self::matchingCloud();
        $databaseFailure->failDatabaseDetail = true;
        $database = self::action(self::plan(
            self::blueprint(database: false),
            $databaseFailure,
            self::databaseState(),
        ), 'database.primary.application');
        self::assertSame(DatabaseDestructiveReadiness::UNKNOWN, $database->databaseDependencies?->readiness());
        self::assertSame(['exact_database'], $database->databaseDependencies->missingRelationships);

        $clusterAddress = new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary');
        $clusterState = new StateDocument(StateVersion::V1, 0, null,
            new StateResource($clusterAddress, ResourceType::DATABASE_CLUSTER, 'cluster-1'));
        $clusterFailure = self::matchingCloud();
        $clusterFailure->failClusterDetail = true;
        $cluster = self::action(self::plan(
            self::blueprint(database: false),
            $clusterFailure,
            $clusterState,
        ), 'database_cluster.primary');
        self::assertSame(DatabaseDestructiveReadiness::UNKNOWN, $cluster->databaseDependencies?->readiness());
        self::assertSame(['exact_database_cluster'], $cluster->databaseDependencies->missingRelationships);
    }

    public function testSafeLogicalDatabaseDeletePlanStillCannotExecute(): void
    {
        $database = new CloudDatabase('database-1', 'cluster-1', 'application', 'cluster-1', [], true);
        $cloud = self::matchingCloud(
            self::mysqlCluster(databaseIds: ['database-1'], childDiscoveryComplete: true),
            [$database],
        );
        $plan = self::plan(self::blueprint(database: false), $cloud, self::databaseState());
        self::assertSame(
            DatabaseDestructiveReadiness::SAFE,
            self::action($plan, 'database.primary.application')->databaseDependencies?->readiness(),
        );

        $states = new NeverStartedStateStore();
        $this->expectException(ApplyRefusedException::class);
        try {
            self::apply()->execute(self::blueprint(database: false), $plan, $cloud, $states);
        } finally {
            self::assertSame(0, $states->beginCalls);
        }
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

    public function testSafeClusterDeleteReadinessStillCannotExecute(): void
    {
        $plan = new ExecutionPlan(new PlanAction(
            new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary'),
            ResourceType::DATABASE_CLUSTER,
            PlanOperation::DELETE,
            'Read-only Cluster delete intent.',
            'cluster-1',
            new DatabaseDependencies(0, 0, 0, false, true),
        ));

        $this->expectException(ApplyRefusedException::class);
        self::apply()->assertSupported($plan);
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

    /** @param list<string> $databaseIds */
    private static function mysqlCluster(
        string $id = 'cluster-1',
        string $type = 'laravel_mysql_8',
        string $status = 'available',
        string $region = 'eu-central-1',
        ?\LaravelCloudBlueprint\Cloud\DTO\CloudDatabaseClusterConfiguration $configuration = null,
        array $databaseIds = [],
        bool $childDiscoveryComplete = false,
    ): CloudDatabaseCluster {
        return new CloudDatabaseCluster(
            $id,
            'primary',
            $type,
            $status,
            $region,
            $configuration ?? new CloudLaravelMySqlConfiguration('db-flex.m-1vcpu-512mb', 5, 1, false, false),
            $databaseIds,
            $childDiscoveryComplete,
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

final class DatabasePlanningCloud implements LaravelCloudDatabaseLifecycleClient
{
    public int $clusterCalls = 0;
    public int $environmentCalls = 0;

    /** @var array<string, int> */
    public array $databaseCalls = [];

    /** @var list<array{string, string}> */
    public array $destructiveDatabaseCalls = [];

    public ?string $environmentDatabaseId = 'database-1';
    public bool $failClusterDetail = false;
    public bool $failDatabaseDetail = false;
    public bool $failSnapshots = false;

    /** @var array<string, list<CloudDatabaseSnapshot>> */
    public array $snapshots = [];

    /** @var list<string> */
    public array $snapshotCalls = [];

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
        if ($this->failClusterDetail) {
            throw new CloudResponseException('Cluster discovery failed.', 'GET', '/databases/clusters/{id}');
        }
        foreach ($this->clusters as $cluster) {
            if ($cluster->id === $clusterId) {
                return $cluster;
            }
        }
        throw new \LogicException('Unknown Cluster detail request.');
    }

    public function databases(string $clusterId): array
    {
        $this->databaseCalls[$clusterId] = ($this->databaseCalls[$clusterId] ?? 0) + 1;
        return $this->databases[$clusterId] ?? [];
    }

    public function databaseSnapshots(string $clusterId): array
    {
        $this->snapshotCalls[] = $clusterId;
        if ($this->failSnapshots) {
            throw new CloudResponseException('Snapshot discovery failed.', 'GET', '/databases/clusters/{id}/snapshots');
        }
        return $this->snapshots[$clusterId] ?? [];
    }

    public function database(string $clusterId, string $databaseId): CloudDatabase
    {
        if ($this->failDatabaseDetail) {
            throw new CloudResponseException('Database discovery failed.', 'GET', '/databases/clusters/{id}/databases/{id}');
        }
        foreach ($this->databases[$clusterId] ?? [] as $database) {
            if ($database->id === $databaseId) {
                return $database;
            }
        }
        throw new \LogicException('Unknown Database detail request.');
    }

    public function databaseWithDestructiveRelationships(string $clusterId, string $databaseId): CloudDatabase
    {
        $this->destructiveDatabaseCalls[] = [$clusterId, $databaseId];
        return $this->database($clusterId, $databaseId);
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
