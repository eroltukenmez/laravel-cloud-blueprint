<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Apply;

use LaravelCloudBlueprint\Apply\ApplyStatus;
use LaravelCloudBlueprint\Apply\Contract\Delay;
use LaravelCloudBlueprint\Apply\CreateOnlyApply;
use LaravelCloudBlueprint\Apply\DatabaseDeletionVerification;
use LaravelCloudBlueprint\Apply\DatabaseClusterDeletionVerification;
use LaravelCloudBlueprint\Apply\DatabaseClusterDeletionReadiness;
use LaravelCloudBlueprint\Apply\DestructiveOutcome;
use LaravelCloudBlueprint\Apply\Exception\ApplyRefusedException;
use LaravelCloudBlueprint\Blueprint\ApplicationDefinition;
use LaravelCloudBlueprint\Blueprint\Blueprint;
use LaravelCloudBlueprint\Blueprint\BlueprintSchemaVersion;
use LaravelCloudBlueprint\Blueprint\DatabaseClusterDefinition;
use LaravelCloudBlueprint\Blueprint\DatabaseClusterDefinitionCollection;
use LaravelCloudBlueprint\Blueprint\DatabaseClusterType;
use LaravelCloudBlueprint\Blueprint\EnvironmentDefinitionCollection;
use LaravelCloudBlueprint\Blueprint\LaravelMySqlConfiguration;
use LaravelCloudBlueprint\Blueprint\LogicalDatabaseDefinitionCollection;
use LaravelCloudBlueprint\Blueprint\SourceDefinition;
use LaravelCloudBlueprint\Blueprint\SourceProvider;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudLogicalDatabaseDeletionClient;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudDatabaseClusterDeletionClient;
use LaravelCloudBlueprint\Cloud\DTO\CloudApplication;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabase;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabaseCluster;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironmentDetails;
use LaravelCloudBlueprint\Cloud\DTO\CloudLaravelMySqlConfiguration;
use LaravelCloudBlueprint\Cloud\DTO\CloudOrganization;
use LaravelCloudBlueprint\Cloud\DTO\CreateApplicationRequest;
use LaravelCloudBlueprint\Cloud\DTO\CreateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\DTO\DatabaseDependencies;
use LaravelCloudBlueprint\Cloud\DTO\SetEnvironmentVariablesRequest;
use LaravelCloudBlueprint\Cloud\DTO\UpdateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\DTO\UpdatedCloudEnvironment;
use LaravelCloudBlueprint\Cloud\Exception\CloudException;
use LaravelCloudBlueprint\Cloud\Exception\CloudResourceNotFoundException;
use LaravelCloudBlueprint\Cloud\Exception\CloudTransportException;
use LaravelCloudBlueprint\Planning\Contract\EnvironmentValueProvider;
use LaravelCloudBlueprint\Planning\ExecutionPlan;
use LaravelCloudBlueprint\Planning\DatabaseDestructiveRole;
use LaravelCloudBlueprint\Planning\DatabaseParentLifecycleDependency;
use LaravelCloudBlueprint\Planning\PlanAction;
use LaravelCloudBlueprint\Planning\PlanOperation;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\Planning\VariableValueResolver;
use LaravelCloudBlueprint\State\Contract\StateStore;
use LaravelCloudBlueprint\State\Contract\StateTransaction;
use LaravelCloudBlueprint\State\Exception\StateStorageException;
use LaravelCloudBlueprint\State\StateDocument;
use LaravelCloudBlueprint\State\StateResource;
use LaravelCloudBlueprint\State\StateOwnershipClassification;
use LaravelCloudBlueprint\State\StateProvenance;
use PHPUnit\Framework\TestCase;

final class LogicalDatabaseDeleteApplyTest extends TestCase
{
    public function testSafeEmptyClusterIsDeletedOnceVerifiedAndCheckpointed(): void
    {
        $cloud = self::cloud([]);
        $states = new LogicalDatabaseDeleteStateStore(StateDocument::empty()->withOrganization('acme')
            ->withResource(new StateResource(self::clusterAddress(), ResourceType::DATABASE_CLUSTER, 'cluster-1')));
        $plan = (new \LaravelCloudBlueprint\Planning\CreatePlan(new VariableValueResolver(new LogicalDatabaseDeleteValues())))
            ->create(self::blueprintWithoutCluster(), $cloud, $states->state);

        $result = self::apply()->execute(self::blueprintWithoutCluster(), $plan, $cloud, $states);

        self::assertSame(ApplyStatus::SUCCESS, $result->status);
        self::assertSame(['cluster-1'], $cloud->clusterDeletes);
        self::assertNull($states->state->find(self::clusterAddress()));
        self::assertSame(DestructiveOutcome::DELETE_CONFIRMED, iterator_to_array($result)[0]->destructiveOutcome);
    }

    public function testDerivedChildIsDeletedAndCheckpointedBeforeCluster(): void
    {
        $derivedAddress = self::databaseAddress('__derived_default');
        $remote = self::database(id: 'derived-1', name: 'production');
        $cloud = self::cloud(['derived-1' => [$remote, self::notFound()]], [$remote]);
        $states = new LogicalDatabaseDeleteStateStore(StateDocument::empty()->withOrganization('acme')
            ->withResource(new StateResource(self::clusterAddress(), ResourceType::DATABASE_CLUSTER, 'cluster-1'))
            ->withResource(new StateResource($derivedAddress, ResourceType::DATABASE, 'derived-1', self::clusterAddress(), StateOwnershipClassification::DERIVED, StateProvenance::CLUSTER_CREATE_RESPONSE)));
        $dependency = new DatabaseParentLifecycleDependency($derivedAddress, StateOwnershipClassification::DERIVED, StateProvenance::CLUSTER_CREATE_RESPONSE, DatabaseDestructiveRole::PARENT_LIFECYCLE_DEPENDENCY);
        $plan = new ExecutionPlan(
            new PlanAction(self::clusterAddress(), ResourceType::DATABASE_CLUSTER, PlanOperation::DELETE, 'delete', 'cluster-1', new DatabaseDependencies(0, 0, 0, false, true, derivedParentDependencyCount: 1), $dependency),
            new PlanAction($derivedAddress, ResourceType::DATABASE, PlanOperation::NO_CHANGE, 'derived', 'derived-1', self::clusterAddress(), StateOwnershipClassification::DERIVED, StateProvenance::CLUSTER_CREATE_RESPONSE, DatabaseDestructiveRole::PARENT_LIFECYCLE_DEPENDENCY),
        );

        $result = self::apply()->execute(self::blueprintWithoutCluster(), $plan, $cloud, $states);

        self::assertSame(ApplyStatus::SUCCESS, $result->status);
        self::assertSame([['cluster-1', 'derived-1']], $cloud->deletes);
        self::assertSame(['cluster-1'], $cloud->clusterDeletes);
        self::assertSame(2, $states->saveCount);
        self::assertSame([], $states->state->resources());
    }

    public function testClusterAbsenceVerificationUsesBoundedGetOnlyPolling(): void
    {
        $cloud = self::cloud([]);
        $verification = new DatabaseClusterDeletionVerification(new LogicalDatabaseDeleteDelay(), 3, 0);

        self::assertSame('present', $verification->verifyAbsent($cloud, 'cluster-1'));
        self::assertSame(3, $cloud->clusterDiscoveries);
        self::assertSame([], $cloud->clusterDeletes);

        $cloud->deleteDatabaseCluster('cluster-1');
        self::assertSame('absent', $verification->verifyAbsent($cloud, 'cluster-1'));
        self::assertSame(4, $cloud->clusterDiscoveries);
        self::assertSame(['cluster-1'], $cloud->clusterDeletes);
    }

    public function testClusterDeleteTransportUncertaintyDoesNotRetryOrCheckpoint(): void
    {
        $failure = new CloudTransportException('timeout', 'DELETE', '/cluster');
        $cloud = self::cloud([], clusterDeleteFailure: $failure);
        $states = new LogicalDatabaseDeleteStateStore(StateDocument::empty()->withOrganization('acme')
            ->withResource(new StateResource(self::clusterAddress(), ResourceType::DATABASE_CLUSTER, 'cluster-1')));
        $plan = (new \LaravelCloudBlueprint\Planning\CreatePlan(new VariableValueResolver(new LogicalDatabaseDeleteValues())))
            ->create(self::blueprintWithoutCluster(), $cloud, $states->state);

        $result = self::apply()->execute(self::blueprintWithoutCluster(), $plan, $cloud, $states);

        self::assertSame(ApplyStatus::PARTIAL_FAILURE, $result->status);
        self::assertSame(['cluster-1'], $cloud->clusterDeletes);
        self::assertNotNull($states->state->find(self::clusterAddress()));
        self::assertSame(DestructiveOutcome::UNCERTAIN, iterator_to_array($result)[0]->destructiveOutcome);
    }

    public function testClusterCheckpointFailureRecoversByExact404WithoutSecondDelete(): void
    {
        $state = StateDocument::empty()->withOrganization('acme')
            ->withResource(new StateResource(self::clusterAddress(), ResourceType::DATABASE_CLUSTER, 'cluster-1'));
        $cloud = self::cloud([]);
        $planner = new \LaravelCloudBlueprint\Planning\CreatePlan(new VariableValueResolver(new LogicalDatabaseDeleteValues()));
        $plan = $planner->create(self::blueprintWithoutCluster(), $cloud, $state);
        $failedStore = new LogicalDatabaseDeleteStateStore($state, true);

        $failed = self::apply()->execute(self::blueprintWithoutCluster(), $plan, $cloud, $failedStore);
        self::assertSame(DestructiveOutcome::STATE_CHECKPOINT_FAILED, iterator_to_array($failed)[0]->destructiveOutcome);
        self::assertNotNull($failedStore->state->find(self::clusterAddress()));
        self::assertSame(['cluster-1'], $cloud->clusterDeletes);

        $recoveryStore = new LogicalDatabaseDeleteStateStore($failedStore->state);
        $recoveryPlan = $planner->create(self::blueprintWithoutCluster(), $cloud, $recoveryStore->state);
        $recovered = self::apply()->execute(self::blueprintWithoutCluster(), $recoveryPlan, $cloud, $recoveryStore);

        self::assertSame(DestructiveOutcome::ALREADY_ABSENT, iterator_to_array($recovered)[0]->destructiveOutcome);
        self::assertSame(['cluster-1'], $cloud->clusterDeletes);
        self::assertNull($recoveryStore->state->find(self::clusterAddress()));
    }

    public function testSafeDatabaseIsDeletedOnceVerifiedAndCheckpointed(): void
    {
        $cloud = self::cloud(['database-1' => [self::database(), self::database(), self::notFound()]]);
        $states = new LogicalDatabaseDeleteStateStore(self::state());

        $result = self::apply()->execute(self::blueprint(), self::plan(), $cloud, $states);

        self::assertSame(ApplyStatus::SUCCESS, $result->status);
        self::assertSame(DestructiveOutcome::DELETE_CONFIRMED, iterator_to_array($result)[0]->destructiveOutcome);
        self::assertSame([['cluster-1', 'database-1']], $cloud->deletes);
        self::assertSame([
            ['cluster-1', 'database-1'],
            ['cluster-1', 'database-1'],
        ], $cloud->destructiveDiscoveries);
        self::assertSame([['cluster-1', 'database-1']], $cloud->verificationDiscoveries);
        self::assertNull($states->state->find(self::databaseAddress()));
        self::assertSame('cluster-1', $states->state->get(self::clusterAddress())->remoteId);
        self::assertSame(8, $states->state->serial);
        self::assertSame(1, $states->saveCount);
    }

    public function testLockedAttachmentOrIncompleteDiscoveryRefusesWithoutDelete(): void
    {
        foreach ([
            self::database(environmentIds: ['environment-secret-id']),
            new CloudDatabase('database-1', 'cluster-1', 'application', 'cluster-1', [], false, ['environments']),
        ] as $rediscovered) {
            $cloud = self::cloud(['database-1' => [$rediscovered]]);
            $states = new LogicalDatabaseDeleteStateStore(self::state());

            $result = self::apply()->execute(self::blueprint(), self::plan(), $cloud, $states);

            self::assertSame(ApplyStatus::FAILED, $result->status);
            self::assertSame(DestructiveOutcome::REFUSED, iterator_to_array($result)[0]->destructiveOutcome);
            self::assertSame([], $cloud->deletes);
            self::assertSame(0, $states->saveCount);
            self::assertNotNull($states->state->find(self::databaseAddress()));
        }
    }

    public function testLockedParentRelationshipMismatchIsConflictWithoutDelete(): void
    {
        $cloud = self::cloud(['database-1' => [self::database(parentId: 'other-cluster')]]);
        $states = new LogicalDatabaseDeleteStateStore(self::state());

        $result = self::apply()->execute(self::blueprint(), self::plan(), $cloud, $states);

        self::assertSame(DestructiveOutcome::CONFLICT, iterator_to_array($result)[0]->destructiveOutcome);
        self::assertSame([], $cloud->deletes);
        self::assertSame(0, $states->saveCount);
    }

    public function testLockedBlueprintOrParentStateChangeInvalidatesApproval(): void
    {
        $cloud = self::cloud(['database-1' => []]);
        $states = new LogicalDatabaseDeleteStateStore(self::state(parentRemoteId: 'changed-cluster'));
        $result = self::apply()->execute(self::blueprint(), self::plan(), $cloud, $states);
        self::assertSame(DestructiveOutcome::CONFLICT, iterator_to_array($result)[0]->destructiveOutcome);
        self::assertSame([], $cloud->deletes);

        $cloud = self::cloud(['database-1' => []]);
        $states = new LogicalDatabaseDeleteStateStore(self::state());
        $result = self::apply()->execute(
            self::blueprint(),
            self::plan(),
            $cloud,
            $states,
            static fn (): Blueprint => self::blueprint(includeDatabase: true),
        );
        self::assertSame(DestructiveOutcome::CONFLICT, iterator_to_array($result)[0]->destructiveOutcome);
        self::assertSame([], $cloud->deletes);
    }

    public function testAlreadyAbsentReconcilesStateWithoutDeleteOrNameTargeting(): void
    {
        $replacement = new CloudDatabase('replacement-id', 'cluster-1', 'application', 'cluster-1', ['env-x'], true);
        $cloud = self::cloud(['database-1' => [self::notFound(), self::notFound()]], [$replacement]);
        $states = new LogicalDatabaseDeleteStateStore(self::state());

        $result = self::apply()->execute(self::blueprint(), self::plan(), $cloud, $states);

        self::assertSame(DestructiveOutcome::ALREADY_ABSENT, iterator_to_array($result)[0]->destructiveOutcome);
        self::assertSame([], $cloud->deletes);
        self::assertNull($states->state->find(self::databaseAddress()));
        self::assertSame(1, $states->saveCount);
    }

    public function test204StillPresentIsUncertainAndRetainsState(): void
    {
        $cloud = self::cloud(['database-1' => [
            self::database(), self::database(), self::database(), self::database(), self::database(),
        ]]);
        $states = new LogicalDatabaseDeleteStateStore(self::state());

        $result = self::apply()->execute(self::blueprint(), self::plan(), $cloud, $states);

        self::assertSame(DestructiveOutcome::UNCERTAIN, iterator_to_array($result)[0]->destructiveOutcome);
        self::assertCount(1, $cloud->deletes);
        self::assertNotNull($states->state->find(self::databaseAddress()));
        self::assertSame(0, $states->saveCount);
    }

    public function testTimeoutOutcomesNeverRetryAndRequireExactVerification(): void
    {
        $absentCloud = self::cloud(
            ['database-1' => [self::database(), self::database(), self::notFound()]],
            deleteFailure: self::timeout(),
        );
        $absentStates = new LogicalDatabaseDeleteStateStore(self::state());
        $absent = self::apply()->execute(self::blueprint(), self::plan(), $absentCloud, $absentStates);
        self::assertSame(DestructiveOutcome::DELETE_CONFIRMED, iterator_to_array($absent)[0]->destructiveOutcome);
        self::assertCount(1, $absentCloud->deletes);
        self::assertNull($absentStates->state->find(self::databaseAddress()));

        $presentCloud = self::cloud(
            ['database-1' => [self::database(), self::database(), self::database(), self::database(), self::database()]],
            deleteFailure: self::timeout(),
        );
        $presentStates = new LogicalDatabaseDeleteStateStore(self::state());
        $present = self::apply()->execute(self::blueprint(), self::plan(), $presentCloud, $presentStates);
        self::assertSame(DestructiveOutcome::UNCERTAIN, iterator_to_array($present)[0]->destructiveOutcome);
        self::assertCount(1, $presentCloud->deletes);
        self::assertNotNull($presentStates->state->find(self::databaseAddress()));

        $failedCloud = self::cloud(
            ['database-1' => [self::database(), self::database(), self::timeout(), self::timeout(), self::timeout()]],
            deleteFailure: self::timeout(),
        );
        $failedStates = new LogicalDatabaseDeleteStateStore(self::state());
        $failed = self::apply()->execute(self::blueprint(), self::plan(), $failedCloud, $failedStates);
        self::assertSame(DestructiveOutcome::UNCERTAIN, iterator_to_array($failed)[0]->destructiveOutcome);
        self::assertCount(1, $failedCloud->deletes);
        self::assertNotNull($failedStates->state->find(self::databaseAddress()));
    }

    public function testDelete404RequiresConfirmedAbsence(): void
    {
        $delete404 = new CloudResourceNotFoundException('not found', 'DELETE', '/database', 404);
        $confirmedCloud = self::cloud(
            ['database-1' => [self::database(), self::database(), self::notFound()]],
            deleteFailure: $delete404,
        );
        $confirmedStates = new LogicalDatabaseDeleteStateStore(self::state());
        $confirmed = self::apply()->execute(self::blueprint(), self::plan(), $confirmedCloud, $confirmedStates);
        self::assertSame(DestructiveOutcome::DELETE_CONFIRMED, iterator_to_array($confirmed)[0]->destructiveOutcome);
        self::assertNull($confirmedStates->state->find(self::databaseAddress()));

        $unknownCloud = self::cloud(
            ['database-1' => [self::database(), self::database(), self::timeout(), self::timeout(), self::timeout()]],
            deleteFailure: $delete404,
        );
        $unknownStates = new LogicalDatabaseDeleteStateStore(self::state());
        $unknown = self::apply()->execute(self::blueprint(), self::plan(), $unknownCloud, $unknownStates);
        self::assertSame(DestructiveOutcome::UNCERTAIN, iterator_to_array($unknown)[0]->destructiveOutcome);
        self::assertNotNull($unknownStates->state->find(self::databaseAddress()));
    }

    public function testCheckpointFailureIsRecoverableByAlreadyAbsentApply(): void
    {
        $cloud = self::cloud(['database-1' => [self::database(), self::database(), self::notFound()]]);
        $failedStates = new LogicalDatabaseDeleteStateStore(self::state(), failSave: true);
        $failed = self::apply()->execute(self::blueprint(), self::plan(), $cloud, $failedStates);
        self::assertSame(DestructiveOutcome::STATE_CHECKPOINT_FAILED, iterator_to_array($failed)[0]->destructiveOutcome);
        self::assertNotNull($failedStates->state->find(self::databaseAddress()));

        $recoveryCloud = self::cloud(['database-1' => [self::notFound(), self::notFound()]]);
        $recoveryStates = new LogicalDatabaseDeleteStateStore($failedStates->state);
        $recovered = self::apply()->execute(self::blueprint(), self::plan(), $recoveryCloud, $recoveryStates);
        self::assertSame(DestructiveOutcome::ALREADY_ABSENT, iterator_to_array($recovered)[0]->destructiveOutcome);
        self::assertSame([], $recoveryCloud->deletes);
        self::assertNull($recoveryStates->state->find(self::databaseAddress()));
    }

    public function testMultipleDeletesCheckpointIndividuallyAndStopAfterLaterUncertainty(): void
    {
        $state = self::state()->withResource(new StateResource(
            self::databaseAddress('reporting'), ResourceType::DATABASE, 'database-2', self::clusterAddress(),
        ));
        $plan = self::plan(includeReporting: true);
        $cloud = self::cloud([
            'database-1' => [self::database(), self::database(), self::notFound()],
            'database-2' => [
                self::database('database-2', 'reporting'),
                self::database('database-2', 'reporting'),
                self::database('database-2', 'reporting'),
                self::database('database-2', 'reporting'),
                self::database('database-2', 'reporting'),
                self::database('database-2', 'reporting'),
            ],
        ], deleteFailures: ['database-2' => self::timeout()]);
        $states = new LogicalDatabaseDeleteStateStore($state);

        $result = self::apply()->execute(self::blueprint(), $plan, $cloud, $states);

        self::assertSame(ApplyStatus::PARTIAL_FAILURE, $result->status);
        $outcomes = iterator_to_array($result);
        self::assertSame(DestructiveOutcome::DELETE_CONFIRMED, $outcomes[0]->destructiveOutcome);
        self::assertSame(DestructiveOutcome::UNCERTAIN, $outcomes[1]->destructiveOutcome);
        self::assertSame(1, $states->saveCount);
        self::assertNull($states->state->find(self::databaseAddress()));
        self::assertNotNull($states->state->find(self::databaseAddress('reporting')));
        self::assertSame([
            ['cluster-1', 'database-1'],
            ['cluster-1', 'database-2'],
        ], $cloud->deletes);
    }

    public function testDatabaseClusterDeleteIsSupportedWhenApprovalGraphAndReadinessAreSafe(): void
    {
        $plan = new ExecutionPlan(new PlanAction(
            self::clusterAddress(),
            ResourceType::DATABASE_CLUSTER,
            PlanOperation::DELETE,
            'structurally safe',
            'cluster-1',
            new DatabaseDependencies(0, 0, 0, false, true),
        ));

        self::apply()->assertSupported($plan);
        self::addToAssertionCount(1);
    }

    private static function apply(): CreateOnlyApply
    {
        return new CreateOnlyApply(
            new VariableValueResolver(new LogicalDatabaseDeleteValues()),
            databaseDeletionVerification: new DatabaseDeletionVerification(new LogicalDatabaseDeleteDelay(), 3, 0),
            databaseClusterDeletionVerification: new DatabaseClusterDeletionVerification(new LogicalDatabaseDeleteDelay(), 12, 0),
            databaseClusterDeletionReadiness: new DatabaseClusterDeletionReadiness(new LogicalDatabaseDeleteDelay(), 12, 0),
        );
    }

    private static function plan(bool $includeReporting = false): ExecutionPlan
    {
        $dependencies = new DatabaseDependencies(0, 0, 0, false, true);
        $actions = [new PlanAction(
            self::databaseAddress(), ResourceType::DATABASE, PlanOperation::DELETE, 'delete',
            'database-1', self::clusterAddress(), $dependencies,
        )];
        if ($includeReporting) {
            $actions[] = new PlanAction(
                self::databaseAddress('reporting'), ResourceType::DATABASE, PlanOperation::DELETE, 'delete',
                'database-2', self::clusterAddress(), $dependencies,
            );
        }
        $actions[] = new PlanAction(
            self::clusterAddress(), ResourceType::DATABASE_CLUSTER, PlanOperation::NO_CHANGE, 'owned', 'cluster-1',
        );
        return new ExecutionPlan(...$actions);
    }

    private static function state(string $parentRemoteId = 'cluster-1'): StateDocument
    {
        return StateDocument::empty()->withOrganization('acme')
            ->withResource(new StateResource(self::clusterAddress(), ResourceType::DATABASE_CLUSTER, $parentRemoteId))
            ->withResource(new StateResource(
                self::databaseAddress(), ResourceType::DATABASE, 'database-1', self::clusterAddress(),
            ))
            ->withSerial(7);
    }

    private static function blueprint(bool $includeDatabase = false): Blueprint
    {
        $databases = $includeDatabase
            ? new LogicalDatabaseDefinitionCollection(new \LaravelCloudBlueprint\Blueprint\LogicalDatabaseDefinition('application'))
            : new LogicalDatabaseDefinitionCollection();
        return new Blueprint(
            BlueprintSchemaVersion::V1,
            'acme',
            new ApplicationDefinition('my-api', 'eu-central-1', new SourceDefinition(SourceProvider::GITHUB, 'acme/api')),
            new EnvironmentDefinitionCollection(),
            new DatabaseClusterDefinitionCollection(new DatabaseClusterDefinition(
                'primary',
                DatabaseClusterType::LARAVEL_MYSQL_8,
                'eu-central-1',
                new LaravelMySqlConfiguration('db-flex.m-1vcpu-512mb', 5, 1, false, false),
                $databases,
            )),
        );
    }

    private static function blueprintWithoutCluster(): Blueprint
    {
        return new Blueprint(
            BlueprintSchemaVersion::V1,
            'acme',
            new ApplicationDefinition('my-api', 'eu-central-1', new SourceDefinition(SourceProvider::GITHUB, 'acme/api')),
            new EnvironmentDefinitionCollection(),
            new DatabaseClusterDefinitionCollection(),
        );
    }

    /**
     * @param array<string, list<CloudDatabase|CloudException>> $discoveries
     * @param list<CloudDatabase> $listed
     * @param array<string, CloudException> $deleteFailures
     */
    private static function cloud(
        array $discoveries,
        array $listed = [],
        ?CloudException $deleteFailure = null,
        array $deleteFailures = [],
        ?CloudException $clusterDeleteFailure = null,
    ): LogicalDatabaseDeleteCloud {
        return new LogicalDatabaseDeleteCloud($discoveries, $listed, $deleteFailure, $deleteFailures, $clusterDeleteFailure);
    }

    /** @param list<string> $environmentIds */
    private static function database(
        string $id = 'database-1',
        string $name = 'application',
        string $parentId = 'cluster-1',
        array $environmentIds = [],
    ): CloudDatabase {
        return new CloudDatabase($id, 'cluster-1', $name, $parentId, $environmentIds, true);
    }

    private static function notFound(): CloudResourceNotFoundException
    {
        return new CloudResourceNotFoundException('not found', 'GET', '/database', 404);
    }

    private static function timeout(): CloudTransportException
    {
        return new CloudTransportException('uncertain timeout', 'DELETE', '/database');
    }

    private static function clusterAddress(): ResourceAddress
    {
        return new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary');
    }

    private static function databaseAddress(string $name = 'application'): ResourceAddress
    {
        return new ResourceAddress(ResourceType::DATABASE, 'primary.' . $name);
    }
}

final class LogicalDatabaseDeleteCloud implements LaravelCloudLogicalDatabaseDeletionClient, LaravelCloudDatabaseClusterDeletionClient
{
    /** @var array<string, list<CloudDatabase|CloudException>> */
    private array $discoveries;
    /** @var list<array{string, string}> */
    public array $deletes = [];

    /** @var list<array{string, string}> */
    public array $destructiveDiscoveries = [];

    /** @var list<array{string, string}> */
    public array $verificationDiscoveries = [];
    /** @var list<string> */
    public array $clusterDeletes = [];
    private bool $clusterDeleted = false;
    public int $clusterDiscoveries = 0;

    /**
     * @param array<string, list<CloudDatabase|CloudException>> $discoveries
     * @param list<CloudDatabase> $listed
     * @param array<string, CloudException> $deleteFailures
     */
    public function __construct(
        array $discoveries,
        private array $listed,
        private readonly ?CloudException $deleteFailure,
        private readonly array $deleteFailures,
        private readonly ?CloudException $clusterDeleteFailure,
    ) {
        $this->discoveries = $discoveries;
    }

    public function organization(): CloudOrganization { return new CloudOrganization('org', 'Acme', 'acme'); }
    public function applications(): array
    {
        return [new CloudApplication('app-1', 'my-api', 'my-api', 'eu-central-1', 'acme/api')];
    }
    public function environments(string $applicationId): array { return []; }
    public function environment(string $environmentId): CloudEnvironmentDetails
    {
        return new CloudEnvironmentDetails($environmentId, 'unused', null);
    }
    public function databaseClusters(): array
    {
        if ($this->clusterDeleted) {
            return [];
        }
        return [new CloudDatabaseCluster(
            'cluster-1', 'primary', 'laravel_mysql_8', 'available', 'eu-central-1',
            new CloudLaravelMySqlConfiguration('db-flex.m-1vcpu-512mb', 5, 1, false, false),
        )];
    }
    public function databaseCluster(string $clusterId): CloudDatabaseCluster
    {
        ++$this->clusterDiscoveries;
        if ($this->clusterDeleted) {
            throw new CloudResourceNotFoundException('not found', 'GET', '/cluster', 404);
        }
        return new CloudDatabaseCluster(
            'cluster-1', 'primary', 'laravel_mysql_8', 'available', 'eu-central-1',
            new CloudLaravelMySqlConfiguration('db-flex.m-1vcpu-512mb', 5, 0, false, false),
            array_map(static fn (CloudDatabase $database): string => $database->id, $this->listed),
            true,
        );
    }
    public function databaseSnapshots(string $clusterId): array { return []; }
    public function databases(string $clusterId): array { return $this->listed; }
    public function database(string $clusterId, string $databaseId): CloudDatabase
    {
        $this->verificationDiscoveries[] = [$clusterId, $databaseId];
        return $this->nextDatabase($databaseId);
    }
    public function databaseWithDestructiveRelationships(string $clusterId, string $databaseId): CloudDatabase
    {
        $this->destructiveDiscoveries[] = [$clusterId, $databaseId];
        return $this->nextDatabase($databaseId);
    }
    private function nextDatabase(string $databaseId): CloudDatabase
    {
        $next = array_shift($this->discoveries[$databaseId]);
        if ($next instanceof CloudException) {
            throw $next;
        }
        if (!$next instanceof CloudDatabase) {
            throw new \LogicException('Missing exact Database discovery fixture for ' . $databaseId);
        }
        return $next;
    }
    public function deleteDatabase(string $clusterId, string $databaseId): void
    {
        $this->deletes[] = [$clusterId, $databaseId];
        $failure = $this->deleteFailures[$databaseId] ?? $this->deleteFailure;
        if ($failure !== null) {
            throw $failure;
        }
        $this->listed = array_values(array_filter($this->listed, static fn (CloudDatabase $database): bool => $database->id !== $databaseId));
    }
    public function deleteDatabaseCluster(string $clusterId): void
    {
        $this->clusterDeletes[] = $clusterId;
        if ($this->clusterDeleteFailure !== null) {
            throw $this->clusterDeleteFailure;
        }
        $this->clusterDeleted = true;
    }
    public function createApplication(CreateApplicationRequest $request): CloudApplication
    {
        throw new \LogicException('Unexpected mutation.');
    }
    public function createEnvironment(string $applicationId, CreateEnvironmentRequest $request): \LaravelCloudBlueprint\Cloud\DTO\CloudEnvironment
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

final class LogicalDatabaseDeleteStateStore implements StateStore, StateTransaction
{
    public int $saveCount = 0;

    public function __construct(public StateDocument $state, private readonly bool $failSave = false) {}
    public function load(): StateDocument { return $this->state; }
    public function begin(): StateTransaction { return $this; }
    public function save(StateDocument $state): StateDocument
    {
        ++$this->saveCount;
        if ($this->failSave) {
            throw new StateStorageException('checkpoint failed');
        }
        return $this->state = $state->withSerial($this->state->serial + 1);
    }
    public function release(): void {}
}

final readonly class LogicalDatabaseDeleteDelay implements Delay
{
    public function milliseconds(int $milliseconds): void {}
}

final readonly class LogicalDatabaseDeleteValues implements EnvironmentValueProvider
{
    public function value(string $name): ?string { return null; }
}
