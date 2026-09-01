<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Apply;

use LaravelCloudBlueprint\Apply\ApplyStatus;
use LaravelCloudBlueprint\Apply\Contract\Delay;
use LaravelCloudBlueprint\Apply\CreateOnlyApply;
use LaravelCloudBlueprint\Apply\DestructiveOutcome;
use LaravelCloudBlueprint\Apply\EnvironmentDeletionVerification;
use LaravelCloudBlueprint\Apply\Exception\ApplyRefusedException;
use LaravelCloudBlueprint\Apply\Exception\StateIdentityConflictException;
use LaravelCloudBlueprint\Blueprint\ApplicationDefinition;
use LaravelCloudBlueprint\Blueprint\Blueprint;
use LaravelCloudBlueprint\Blueprint\BlueprintSchemaVersion;
use LaravelCloudBlueprint\Blueprint\EnvironmentDefinition;
use LaravelCloudBlueprint\Blueprint\EnvironmentDefinitionCollection;
use LaravelCloudBlueprint\Blueprint\SourceDefinition;
use LaravelCloudBlueprint\Blueprint\SourceProvider;
use LaravelCloudBlueprint\Blueprint\VariableDefinitionCollection;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudEnvironmentMutationClient;
use LaravelCloudBlueprint\Cloud\DTO\CloudApplication;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironment;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironmentDetails;
use LaravelCloudBlueprint\Cloud\DTO\CloudOrganization;
use LaravelCloudBlueprint\Cloud\DTO\CreateApplicationRequest;
use LaravelCloudBlueprint\Cloud\DTO\CreateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\DTO\EnvironmentDependencies;
use LaravelCloudBlueprint\Cloud\DTO\SetEnvironmentVariablesRequest;
use LaravelCloudBlueprint\Cloud\DTO\UpdateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\DTO\UpdatedCloudEnvironment;
use LaravelCloudBlueprint\Cloud\Exception\CloudException;
use LaravelCloudBlueprint\Cloud\Exception\CloudApiException;
use LaravelCloudBlueprint\Cloud\Exception\CloudTransportException;
use LaravelCloudBlueprint\Cloud\Exception\CloudResourceNotFoundException;
use LaravelCloudBlueprint\Planning\Contract\EnvironmentValueProvider;
use LaravelCloudBlueprint\Planning\ExecutionPlan;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EnvironmentDeleteApplyTest extends TestCase
{
    public function testSafeApprovedDeleteIsConfirmedBeforeStateCheckpoint(): void
    {
        $cloud = new DeleteCloud([[self::environment()], []]);
        $unrelated = new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'analytics');
        $states = new DeleteStateStore(self::state()->withResource(
            new StateResource($unrelated, ResourceType::DATABASE_CLUSTER, 'cluster-unrelated'),
        ));

        $result = self::apply()->execute(self::blueprint(), self::plan(), $cloud, $states);

        self::assertSame(ApplyStatus::SUCCESS, $result->status);
        self::assertSame(DestructiveOutcome::DELETE_CONFIRMED, iterator_to_array($result)[0]->destructiveOutcome);
        self::assertSame(['env-old'], $cloud->deletedIds);
        self::assertNull($states->state->find(self::environmentAddress()));
        self::assertSame('app-old', $states->state->get(self::applicationAddress())->remoteId);
        self::assertSame('cluster-unrelated', $states->state->get($unrelated)->remoteId);
        self::assertSame(8, $states->state->serial);
        self::assertSame(1, $states->saveCount);
        self::assertSame(['lock', 'get:app-old', 'delete:env-old', 'get:app-old', 'save', 'release'], $states->eventsWith($cloud));
    }

    public function testExactIdAlreadyAbsentReconcilesStateWithoutDeletingSameNameReplacement(): void
    {
        $replacement = self::environment('env-new', 'preview');
        $cloud = new DeleteCloud([[$replacement]]);
        $states = new DeleteStateStore(self::state());

        $result = self::apply()->execute(self::blueprint(), self::plan(), $cloud, $states);

        self::assertSame(DestructiveOutcome::ALREADY_ABSENT, iterator_to_array($result)[0]->destructiveOutcome);
        self::assertSame([], $cloud->deletedIds);
        self::assertSame(1, $states->saveCount);
        self::assertNull($states->state->find(self::environmentAddress()));
    }

    /** @return iterable<string, array{EnvironmentDependencies}> */
    public static function blockers(): iterable
    {
        yield 'database' => [self::dependencies(databaseId: 'db')];
        yield 'cache' => [self::dependencies(cacheId: 'cache')];
        yield 'websocket' => [self::dependencies(websocketId: 'ws')];
        yield 'domain' => [self::dependencies(domains: 1)];
        yield 'bucket' => [self::dependencies(filesystems: 1)];
        yield 'default environment' => [self::dependencies(isDefault: true)];
        yield 'secret' => [self::dependencies(secrets: 1)];
        yield 'deployment' => [self::dependencies(deployments: 1)];
    }

    public function testOrdinaryInstanceIsInformationalAndExactIdIsDeletedAfterLockedRediscovery(): void
    {
        $dependencies = self::dependencies(instances: 1);
        $cloud = new DeleteCloud([
            [self::environment(dependencies: $dependencies)],
            [],
        ]);
        $states = new DeleteStateStore(self::state());

        $result = self::apply()->execute(self::blueprint(), self::plan($dependencies), $cloud, $states);

        self::assertSame(ApplyStatus::SUCCESS, $result->status);
        self::assertSame(['env-old'], $cloud->deletedIds);
        self::assertSame(['lock', 'get:app-old', 'delete:env-old', 'get:app-old', 'save', 'release'], $states->eventsWith($cloud));
    }

    public function testOrdinaryInstanceWithActualBlockerRefusesBeforeDeleteTransport(): void
    {
        $dependencies = self::dependencies(databaseId: 'db', instances: 1);
        $cloud = new DeleteCloud([]);
        $states = new DeleteStateStore(self::state());

        $this->expectException(ApplyRefusedException::class);
        try {
            self::apply()->execute(self::blueprint(), self::plan($dependencies), $cloud, $states);
        } finally {
            self::assertSame(0, $cloud->deleteCalls);
            self::assertSame([], $states->events);
        }
    }

    #[DataProvider('blockers')]
    public function testKnownBlockerRefusesBeforeLockOrMutation(EnvironmentDependencies $dependencies): void
    {
        $cloud = new DeleteCloud([]);
        $states = new DeleteStateStore(self::state());

        $this->expectException(ApplyRefusedException::class);
        try {
            self::apply()->execute(self::blueprint(), self::plan($dependencies), $cloud, $states);
        } finally {
            self::assertSame(0, $cloud->deleteCalls);
            self::assertSame(0, $states->saveCount);
            self::assertSame([], $states->events);
        }
    }

    public function testIncompleteDiscoveryRefusesBeforeLockOrMutation(): void
    {
        $cloud = new DeleteCloud([]);
        $states = new DeleteStateStore(self::state());

        $this->expectException(ApplyRefusedException::class);
        try {
            self::apply()->execute(self::blueprint(), self::plan(EnvironmentDependencies::incomplete()), $cloud, $states);
        } finally {
            self::assertSame(0, $cloud->deleteCalls);
            self::assertSame(0, $states->saveCount);
            self::assertSame([], $states->events);
        }
    }

    public function testLockedRediscoveryCanChangeSafeToBlockedOrUnknown(): void
    {
        foreach ([
            self::dependencies(databaseId: 'db'),
            self::dependencies(domains: 1),
            self::dependencies(isDefault: true),
            EnvironmentDependencies::incomplete(),
        ] as $dependencies) {
            $cloud = new DeleteCloud([[self::environment(dependencies: $dependencies)]]);
            $states = new DeleteStateStore(self::state());

            $result = self::apply()->execute(self::blueprint(), self::plan(), $cloud, $states);

            self::assertSame(ApplyStatus::FAILED, $result->status);
            self::assertSame(DestructiveOutcome::REFUSED, iterator_to_array($result)[0]->destructiveOutcome);
            self::assertSame(0, $cloud->deleteCalls);
            self::assertSame(0, $states->saveCount);
        }
    }

    public function testLockedStateAndBlueprintChangesInvalidateApproval(): void
    {
        $changedState = self::state('env-new');
        $states = new DeleteStateStore($changedState);
        $cloud = new DeleteCloud([]);

        try {
            self::apply()->execute(self::blueprint(), self::plan(), $cloud, $states);
            self::fail('Expected State conflict.');
        } catch (StateIdentityConflictException) {
        }
        self::assertSame(0, $cloud->deleteCalls);
        self::assertSame(0, $states->saveCount);

        $states = new DeleteStateStore(self::state());
        $result = self::apply()->execute(
            self::blueprint(),
            self::plan(),
            $cloud,
            $states,
            static fn (): Blueprint => self::blueprint(includeEnvironment: true),
        );
        self::assertSame(ApplyStatus::FAILED, $result->status);
        self::assertSame(DestructiveOutcome::CONFLICT, iterator_to_array($result)[0]->destructiveOutcome);
        self::assertSame(0, $cloud->deleteCalls);
        self::assertSame(0, $states->saveCount);
    }

    public function testTimeoutThenAbsentConfirmsWithoutRetryingDelete(): void
    {
        $cloud = new DeleteCloud([[self::environment()], []], deleteFailure: self::timeout());
        $states = new DeleteStateStore(self::state());

        $result = self::apply()->execute(self::blueprint(), self::plan(), $cloud, $states);

        self::assertSame(DestructiveOutcome::DELETE_CONFIRMED, iterator_to_array($result)[0]->destructiveOutcome);
        self::assertSame(1, $cloud->deleteCalls);
        self::assertSame(1, $states->saveCount);
    }

    public function testDelete404RequiresAndUsesAuthoritativeAbsenceVerification(): void
    {
        $notFound = new CloudResourceNotFoundException('not found', 'DELETE', '/environments/env-old', 404);
        $cloud = new DeleteCloud([[self::environment()], []], deleteFailure: $notFound);
        $states = new DeleteStateStore(self::state());

        $result = self::apply()->execute(self::blueprint(), self::plan(), $cloud, $states);

        self::assertSame(DestructiveOutcome::DELETE_CONFIRMED, iterator_to_array($result)[0]->destructiveOutcome);
        self::assertSame(1, $cloud->deleteCalls);
        self::assertSame(1, $states->saveCount);
    }

    public function testParentAddressAndRemoteIdentityChangesInvalidateApproval(): void
    {
        $oldParent = self::applicationAddress();
        $otherParent = new ResourceAddress(ResourceType::APPLICATION, 'other');
        $wrongParentState = StateDocument::empty()->withOrganization('acme')
            ->withResource(new StateResource($oldParent, ResourceType::APPLICATION, 'app-old'))
            ->withResource(new StateResource($otherParent, ResourceType::APPLICATION, 'app-other'))
            ->withResource(new StateResource(self::environmentAddress(), ResourceType::ENVIRONMENT, 'env-old', $otherParent));

        foreach ([
            $wrongParentState,
            self::stateWithParentRemoteId('app-new'),
        ] as $state) {
            $cloud = new DeleteCloud([]);
            $states = new DeleteStateStore($state);
            try {
                self::apply()->execute(self::blueprint(), self::plan(), $cloud, $states);
                self::fail('Expected stale parent conflict.');
            } catch (StateIdentityConflictException) {
            }
            self::assertSame(0, $cloud->deleteCalls);
            self::assertSame(0, $states->saveCount);
        }
    }

    public function testLockedCloudParentMismatchRefusesWithoutDeleteOrStateSave(): void
    {
        $remote = new CloudEnvironment('env-old', 'app-other', 'preview', 'main', dependencies: self::dependencies());
        $cloud = new DeleteCloud([[$remote]]);
        $states = new DeleteStateStore(self::state());

        $result = self::apply()->execute(self::blueprint(), self::plan(), $cloud, $states);

        self::assertSame(DestructiveOutcome::CONFLICT, iterator_to_array($result)[0]->destructiveOutcome);
        self::assertSame(0, $cloud->deleteCalls);
        self::assertSame(0, $states->saveCount);
    }

    public function testTimeoutThenPresentOrDiscoveryFailureRetainsStateAndNeverRetriesDelete(): void
    {
        $presentCloud = new DeleteCloud([
            [self::environment()], [self::environment()], [self::environment()], [self::environment()],
        ], deleteFailure: self::timeout());
        $presentStates = new DeleteStateStore(self::state());
        $present = self::apply()->execute(self::blueprint(), self::plan(), $presentCloud, $presentStates);
        self::assertSame(DestructiveOutcome::UNCERTAIN, iterator_to_array($present)[0]->destructiveOutcome);
        self::assertSame(1, $presentCloud->deleteCalls);
        self::assertSame(0, $presentStates->saveCount);

        $failureCloud = new DeleteCloud([
            [self::environment()], self::timeout(), self::timeout(), self::timeout(),
        ], deleteFailure: self::timeout());
        $failureStates = new DeleteStateStore(self::state());
        $failure = self::apply()->execute(self::blueprint(), self::plan(), $failureCloud, $failureStates);
        self::assertSame(DestructiveOutcome::UNCERTAIN, iterator_to_array($failure)[0]->destructiveOutcome);
        self::assertSame(1, $failureCloud->deleteCalls);
        self::assertSame(0, $failureStates->saveCount);
    }

    public function test204WhileStillPresentRetainsStateAndDoesNotSendSecondDelete(): void
    {
        $cloud = new DeleteCloud([
            [self::environment()], [self::environment()], [self::environment()], [self::environment()],
        ]);
        $states = new DeleteStateStore(self::state());

        $result = self::apply()->execute(self::blueprint(), self::plan(), $cloud, $states);

        self::assertSame(DestructiveOutcome::UNCERTAIN, iterator_to_array($result)[0]->destructiveOutcome);
        self::assertSame(1, $cloud->deleteCalls);
        self::assertSame(0, $states->saveCount);
        self::assertNotNull($states->state->find(self::environmentAddress()));
    }

    public function testConfirmedDeletionWithCheckpointFailureIsPartialAndRecoverable(): void
    {
        $cloud = new DeleteCloud([[self::environment()], []]);
        $states = new DeleteStateStore(self::state(), failSave: true);

        $result = self::apply()->execute(self::blueprint(), self::plan(), $cloud, $states);

        self::assertSame(ApplyStatus::PARTIAL_FAILURE, $result->status);
        self::assertSame(DestructiveOutcome::STATE_CHECKPOINT_FAILED, iterator_to_array($result)[0]->destructiveOutcome);
        self::assertNotNull($states->state->find(self::environmentAddress()));
        self::assertSame(7, $states->state->serial);
    }

    public function testCheckpointedDeletionSurvivesLaterCreateFailureAsPartialApply(): void
    {
        $createFailure = new CloudApiException('create failed', 'POST', '/applications/app-old/environments', 422);
        $cloud = new DeleteCloud([[self::environment()], []], createFailure: $createFailure);
        $states = new DeleteStateStore(self::state());
        $application = self::applicationAddress();
        $staging = new ResourceAddress(ResourceType::ENVIRONMENT, 'staging');
        $plan = new ExecutionPlan(
            new PlanAction(
                self::environmentAddress(),
                ResourceType::ENVIRONMENT,
                PlanOperation::DELETE,
                'delete',
                'env-old',
                $application,
                self::dependencies(),
            ),
            new PlanAction($application, ResourceType::APPLICATION, PlanOperation::NO_CHANGE, 'owned', 'app-old'),
            new PlanAction($staging, ResourceType::ENVIRONMENT, PlanOperation::CREATE, 'create'),
        );

        $result = self::apply()->execute(self::blueprint(includeEnvironment: true, environmentName: 'staging'), $plan, $cloud, $states);

        self::assertSame(ApplyStatus::PARTIAL_FAILURE, $result->status);
        self::assertSame(DestructiveOutcome::DELETE_CONFIRMED, iterator_to_array($result)[0]->destructiveOutcome);
        self::assertNull($states->state->find(self::environmentAddress()));
        self::assertNull($states->state->find($staging));
        self::assertSame(8, $states->state->serial);
        self::assertSame(1, $states->saveCount);
        self::assertSame(1, $cloud->deleteCalls);
    }

    private static function apply(): CreateOnlyApply
    {
        return new CreateOnlyApply(
            new VariableValueResolver(new DeleteEnvironmentValues()),
            deletionVerification: new EnvironmentDeletionVerification(new DeleteDelay(), 3, 0),
        );
    }

    private static function plan(?EnvironmentDependencies $dependencies = null): ExecutionPlan
    {
        $application = self::applicationAddress();

        return new ExecutionPlan(
            new PlanAction(
                self::environmentAddress(),
                ResourceType::ENVIRONMENT,
                PlanOperation::DELETE,
                'delete',
                'env-old',
                $application,
                $dependencies ?? self::dependencies(),
            ),
            new PlanAction($application, ResourceType::APPLICATION, PlanOperation::NO_CHANGE, 'owned', 'app-old'),
        );
    }

    private static function state(string $environmentId = 'env-old'): StateDocument
    {
        $application = self::applicationAddress();

        return StateDocument::empty()->withOrganization('acme')
            ->withResource(new StateResource($application, ResourceType::APPLICATION, 'app-old'))
            ->withResource(new StateResource(self::environmentAddress(), ResourceType::ENVIRONMENT, $environmentId, $application))
            ->withSerial(7);
    }

    private static function stateWithParentRemoteId(string $parentRemoteId): StateDocument
    {
        $application = self::applicationAddress();

        return StateDocument::empty()->withOrganization('acme')
            ->withResource(new StateResource($application, ResourceType::APPLICATION, $parentRemoteId))
            ->withResource(new StateResource(self::environmentAddress(), ResourceType::ENVIRONMENT, 'env-old', $application));
    }

    private static function blueprint(bool $includeEnvironment = false, string $environmentName = 'preview'): Blueprint
    {
        return new Blueprint(
            BlueprintSchemaVersion::V1,
            'acme',
            new ApplicationDefinition('my-api', 'eu-central-1', new SourceDefinition(SourceProvider::GITHUB, 'acme/api')),
            $includeEnvironment
                ? new EnvironmentDefinitionCollection(new EnvironmentDefinition($environmentName, 'main', new VariableDefinitionCollection()))
                : new EnvironmentDefinitionCollection(),
        );
    }

    private static function environment(
        string $id = 'env-old',
        string $name = 'preview',
        ?EnvironmentDependencies $dependencies = null,
    ): CloudEnvironment {
        return new CloudEnvironment($id, 'app-old', $name, 'main', dependencies: $dependencies ?? self::dependencies());
    }

    private static function dependencies(
        ?string $databaseId = null,
        ?string $cacheId = null,
        ?string $websocketId = null,
        int $domains = 0,
        int $instances = 0,
        int $deployments = 0,
        int $secrets = 0,
        int $filesystems = 0,
        bool $isDefault = false,
    ): EnvironmentDependencies {
        return new EnvironmentDependencies(
            $databaseId,
            $cacheId,
            $websocketId,
            $domains,
            $instances,
            $deployments,
            $secrets,
            $filesystems,
            false,
            $isDefault,
            true,
        );
    }

    private static function timeout(): CloudTransportException
    {
        return new CloudTransportException('uncertain timeout', 'DELETE', '/environments/env-old');
    }

    private static function applicationAddress(): ResourceAddress
    {
        return new ResourceAddress(ResourceType::APPLICATION, 'my-api');
    }

    private static function environmentAddress(): ResourceAddress
    {
        return new ResourceAddress(ResourceType::ENVIRONMENT, 'preview');
    }
}

final class DeleteCloud implements LaravelCloudEnvironmentMutationClient
{
    /** @var list<list<CloudEnvironment>|CloudException> */
    private array $discoveries;
    /** @var list<string> */
    public array $events = [];
    /** @var list<string> */
    public array $deletedIds = [];
    public int $deleteCalls = 0;

    /** @param list<list<CloudEnvironment>|CloudException> $discoveries */
    public function __construct(
        array $discoveries,
        private readonly ?CloudException $deleteFailure = null,
        private readonly ?CloudException $createFailure = null,
    )
    {
        $this->discoveries = $discoveries;
    }

    public function organization(): CloudOrganization { return new CloudOrganization('org', 'Acme', 'acme'); }
    public function applications(): array { return []; }
    public function environments(string $applicationId): array
    {
        $this->events[] = 'get:' . $applicationId;
        $next = array_shift($this->discoveries) ?? [];
        if ($next instanceof CloudException) {
            throw $next;
        }

        return $next;
    }
    public function environment(string $environmentId): CloudEnvironmentDetails { return new CloudEnvironmentDetails($environmentId, 'preview', null); }
    public function createApplication(CreateApplicationRequest $request): CloudApplication { throw new \LogicException('Unexpected create.'); }
    public function createEnvironment(string $applicationId, CreateEnvironmentRequest $request): CloudEnvironment
    {
        if ($this->createFailure !== null) {
            throw $this->createFailure;
        }

        throw new \LogicException('Unexpected create.');
    }
    public function updateEnvironment(string $environmentId, UpdateEnvironmentRequest $request): UpdatedCloudEnvironment { throw new \LogicException('Unexpected update.'); }
    public function setEnvironmentVariables(string $environmentId, SetEnvironmentVariablesRequest $request): void { throw new \LogicException('Unexpected variables.'); }
    public function deleteEnvironment(string $environmentId): void
    {
        ++$this->deleteCalls;
        $this->deletedIds[] = $environmentId;
        $this->events[] = 'delete:' . $environmentId;
        if ($this->deleteFailure !== null) {
            throw $this->deleteFailure;
        }
    }
}

final class DeleteStateStore implements StateStore, StateTransaction
{
    /** @var list<string> */
    public array $events = [];
    public int $saveCount = 0;

    public function __construct(public StateDocument $state, private readonly bool $failSave = false) {}
    public function load(): StateDocument { return $this->state; }
    public function begin(): StateTransaction { $this->events[] = 'lock'; return $this; }
    public function save(StateDocument $state): StateDocument
    {
        ++$this->saveCount;
        $this->events[] = 'save';
        if ($this->failSave) {
            throw new StateStorageException('checkpoint failed');
        }
        return $this->state = $state->withSerial($this->state->serial + 1);
    }
    public function release(): void { $this->events[] = 'release'; }

    /** @return list<string> */
    public function eventsWith(DeleteCloud $cloud): array
    {
        return [$this->events[0], ...$cloud->events, ...array_slice($this->events, 1)];
    }
}

final readonly class DeleteDelay implements Delay
{
    public function milliseconds(int $milliseconds): void {}
}

final readonly class DeleteEnvironmentValues implements EnvironmentValueProvider
{
    public function value(string $name): ?string { return null; }
}
