<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Apply;

use LaravelCloudBlueprint\Apply\ApplyStatus;
use LaravelCloudBlueprint\Apply\CreateOnlyApply;
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
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudClient;
use LaravelCloudBlueprint\Cloud\DTO\CloudApplication;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironment;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironmentDetails;
use LaravelCloudBlueprint\Cloud\DTO\CloudOrganization;
use LaravelCloudBlueprint\Cloud\DTO\CreateApplicationRequest;
use LaravelCloudBlueprint\Cloud\DTO\CreateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\DTO\SetEnvironmentVariablesRequest;
use LaravelCloudBlueprint\Cloud\Exception\CloudApiException;
use LaravelCloudBlueprint\Planning\ExecutionPlan;
use LaravelCloudBlueprint\Planning\Contract\EnvironmentValueProvider;
use LaravelCloudBlueprint\Planning\PlanAction;
use LaravelCloudBlueprint\Planning\PlanOperation;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\Planning\VariableValueResolver;
use LaravelCloudBlueprint\State\Contract\StateStore;
use LaravelCloudBlueprint\State\Contract\StateTransaction;
use LaravelCloudBlueprint\State\Exception\StateLockedException;
use LaravelCloudBlueprint\State\Exception\StateStorageException;
use LaravelCloudBlueprint\State\StateDocument;
use LaravelCloudBlueprint\State\StateResource;
use PHPUnit\Framework\TestCase;

final class CreateOnlyApplyTest extends TestCase
{
    public function testFullyCreatePlanLocksCreatesAndCheckpointsInDependencyOrder(): void
    {
        $events = new ApplyEvents();
        $cloud = new ApplyCloudClient($events);
        $states = new ApplyStateStore($events);

        $result = self::apply()->execute(self::blueprint(), self::createPlan(), $cloud, $states);

        self::assertSame(ApplyStatus::SUCCESS, $result->status);
        self::assertSame(3, $result->createdCount());
        self::assertSame([
            'lock', 'create:application', 'save:application.my-api',
            'create:environment.production', 'save:environment.production',
            'create:environment.staging', 'save:environment.staging', 'release',
        ], $events->values);
        self::assertSame('acme', $states->state->organization);
        self::assertSame('app-created', $states->state->get(self::address(ResourceType::APPLICATION, 'my-api'))->remoteId);
        self::assertSame('application.my-api', (string) $states->state->get(self::address(ResourceType::ENVIRONMENT, 'production'))->parent);
    }

    public function testExistingApplicationCreatesOnlyMissingEnvironmentAndAcceptsMatchingStateIdentity(): void
    {
        $events = new ApplyEvents();
        $cloud = new ApplyCloudClient($events);
        $states = new ApplyStateStore($events, StateDocument::empty()->withOrganization('acme')->withResource(
            new StateResource(self::address(ResourceType::APPLICATION, 'my-api'), ResourceType::APPLICATION, 'app-existing'),
        ));
        $plan = new ExecutionPlan(
            self::action(ResourceType::APPLICATION, 'my-api', PlanOperation::NO_CHANGE, 'app-existing'),
            self::action(ResourceType::ENVIRONMENT, 'production', PlanOperation::CREATE),
        );

        $result = self::apply()->execute(self::blueprint(), $plan, $cloud, $states);

        self::assertSame(1, $result->createdCount());
        self::assertSame(1, $result->unchangedCount());
        self::assertSame(['lock', 'create:environment.production', 'save:environment.production', 'release'], $events->values);
        self::assertSame('app-existing', $cloud->environmentApplicationIds[0]);
    }

    public function testNoChangePerformsNoMutationAndDoesNotManageExistingRemoteResources(): void
    {
        $events = new ApplyEvents();
        $states = new ApplyStateStore($events);
        $plan = new ExecutionPlan(self::action(ResourceType::APPLICATION, 'my-api', PlanOperation::NO_CHANGE, 'app-existing'));

        $result = self::apply()->execute(self::blueprint(), $plan, new ApplyCloudClient($events), $states);

        self::assertSame(ApplyStatus::SUCCESS, $result->status);
        self::assertSame(1, $result->unchangedCount());
        self::assertSame([], $states->state->resources());
        self::assertSame(['lock', 'release'], $events->values);
    }

    public function testUnsupportedPlanRefusesBeforeLockOrMutation(): void
    {
        $events = new ApplyEvents();
        $plan = new ExecutionPlan(self::action(ResourceType::APPLICATION, 'my-api', PlanOperation::UNSUPPORTED));

        $this->expectException(ApplyRefusedException::class);
        try {
            self::apply()->execute(self::blueprint(), $plan, new ApplyCloudClient($events), new ApplyStateStore($events));
        } finally {
            self::assertSame([], $events->values);
        }
    }

    public function testVariableAddressMissingFromBlueprintIsRefusedBeforeLockOrMutation(): void
    {
        $events = new ApplyEvents();
        $cloud = new ApplyCloudClient($events);
        $states = new ApplyStateStore($events);
        $plan = new ExecutionPlan(
            self::action(ResourceType::VARIABLE, 'production.APP_KEY', PlanOperation::CREATE),
        );

        try {
            self::apply()->execute(self::blueprint(), $plan, $cloud, $states);
            self::fail('Expected variable mutation refusal.');
        } catch (ApplyRefusedException $exception) {
            self::assertStringContainsString('does not exist in the blueprint', $exception->getMessage());
        }

        self::assertSame([], $events->values);
        self::assertSame([], $states->state->resources());
    }

    public function testStateOrganizationMismatchAbortsBeforeMutationAndReleasesLock(): void
    {
        $events = new ApplyEvents();
        $states = new ApplyStateStore($events, StateDocument::empty()->withOrganization('other'));

        $this->expectException(StateIdentityConflictException::class);
        try {
            self::apply()->execute(self::blueprint(), self::createPlan(), new ApplyCloudClient($events), $states);
        } finally {
            self::assertSame(['lock', 'release'], $events->values);
        }
    }

    public function testConflictingStateRemoteIdentityAbortsButMatchingIdentityIsAccepted(): void
    {
        $events = new ApplyEvents();
        $state = StateDocument::empty()->withOrganization('acme')->withResource(
            new StateResource(self::address(ResourceType::APPLICATION, 'my-api'), ResourceType::APPLICATION, 'app-old'),
        );
        $plan = new ExecutionPlan(self::action(ResourceType::APPLICATION, 'my-api', PlanOperation::NO_CHANGE, 'app-new'));

        $this->expectException(StateIdentityConflictException::class);
        self::apply()->execute(self::blueprint(), $plan, new ApplyCloudClient($events), new ApplyStateStore($events, $state));
    }

    public function testApplicationCreateFailureWritesNoStateAndReleasesLock(): void
    {
        $events = new ApplyEvents();
        $cloud = new ApplyCloudClient($events, failApplication: true);
        $states = new ApplyStateStore($events);

        $result = self::apply()->execute(self::blueprint(), self::createPlan(), $cloud, $states);

        self::assertSame(ApplyStatus::FAILED, $result->status);
        self::assertSame([], $states->state->resources());
        self::assertSame(['lock', 'create:application', 'release'], $events->values);
    }

    public function testApplicationCheckpointFailureStopsBeforeEnvironmentAndIsPartial(): void
    {
        $events = new ApplyEvents();
        $states = new ApplyStateStore($events, failSaveNumber: 1);

        $result = self::apply()->execute(self::blueprint(), self::createPlan(), new ApplyCloudClient($events), $states);

        self::assertSame(ApplyStatus::PARTIAL_FAILURE, $result->status);
        self::assertSame(['lock', 'create:application', 'save:application.my-api', 'release'], $events->values);
    }

    public function testApplicationRemainsCheckpointedWhenEnvironmentFails(): void
    {
        $events = new ApplyEvents();
        $states = new ApplyStateStore($events);
        $cloud = new ApplyCloudClient($events, failEnvironmentNumber: 1);

        $result = self::apply()->execute(self::blueprint(), self::createPlan(), $cloud, $states);

        self::assertSame(ApplyStatus::PARTIAL_FAILURE, $result->status);
        self::assertNotNull($states->state->find(self::address(ResourceType::APPLICATION, 'my-api')));
        self::assertNull($states->state->find(self::address(ResourceType::ENVIRONMENT, 'production')));
    }

    public function testFirstEnvironmentRemainsCheckpointedWhenSecondFails(): void
    {
        $events = new ApplyEvents();
        $states = new ApplyStateStore($events);
        $cloud = new ApplyCloudClient($events, failEnvironmentNumber: 2);

        $result = self::apply()->execute(self::blueprint(), self::createPlan(), $cloud, $states);

        self::assertSame(ApplyStatus::PARTIAL_FAILURE, $result->status);
        self::assertNotNull($states->state->find(self::address(ResourceType::ENVIRONMENT, 'production')));
        self::assertNull($states->state->find(self::address(ResourceType::ENVIRONMENT, 'staging')));
    }

    public function testLockFailurePerformsZeroCloudMutation(): void
    {
        $events = new ApplyEvents();
        $states = new ApplyStateStore($events, failLock: true);

        $this->expectException(StateLockedException::class);
        try {
            self::apply()->execute(self::blueprint(), self::createPlan(), new ApplyCloudClient($events), $states);
        } finally {
            self::assertSame(['lock'], $events->values);
        }
    }

    private static function createPlan(): ExecutionPlan
    {
        return new ExecutionPlan(
            self::action(ResourceType::APPLICATION, 'my-api', PlanOperation::CREATE),
            self::action(ResourceType::ENVIRONMENT, 'production', PlanOperation::CREATE),
            self::action(ResourceType::ENVIRONMENT, 'staging', PlanOperation::CREATE),
        );
    }

    private static function apply(): CreateOnlyApply
    {
        return new CreateOnlyApply(new VariableValueResolver(new ApplyEnvironmentValueProvider()));
    }

    private static function action(ResourceType $type, string $name, PlanOperation $operation, ?string $remoteId = null): PlanAction
    {
        return new PlanAction(self::address($type, $name), $type, $operation, 'test', $remoteId);
    }

    private static function address(ResourceType $type, string $name): ResourceAddress
    {
        return new ResourceAddress($type, $name);
    }

    private static function blueprint(): Blueprint
    {
        return new Blueprint(
            BlueprintSchemaVersion::V1,
            'acme',
            new ApplicationDefinition('my-api', 'eu-central-1', new SourceDefinition(SourceProvider::GITHUB, 'acme/my-api')),
            new EnvironmentDefinitionCollection(
                new EnvironmentDefinition('production', 'main', new VariableDefinitionCollection()),
                new EnvironmentDefinition('staging', 'develop', new VariableDefinitionCollection()),
            ),
        );
    }
}

final class ApplyEvents
{
    /** @var list<string> */
    public array $values = [];
}

final class ApplyCloudClient implements LaravelCloudClient
{
    /** @var list<string> */
    public array $environmentApplicationIds = [];
    private int $environmentAttempt = 0;

    public function __construct(
        private readonly ApplyEvents $events,
        private readonly bool $failApplication = false,
        private readonly ?int $failEnvironmentNumber = null,
    ) {
    }

    public function organization(): CloudOrganization { return new CloudOrganization('org', 'Acme', 'acme'); }
    public function applications(): array { return []; }
    public function environments(string $applicationId): array { return []; }
    public function environment(string $environmentId): CloudEnvironmentDetails
    {
        return new CloudEnvironmentDetails($environmentId, 'production', null);
    }

    public function createApplication(CreateApplicationRequest $request): CloudApplication
    {
        $this->events->values[] = 'create:application';
        if ($this->failApplication) {
            throw new CloudApiException('Application creation failed.', 'POST', '/applications', 422);
        }
        return new CloudApplication('app-created', $request->name, $request->name, $request->region, $request->repository);
    }

    public function createEnvironment(string $applicationId, CreateEnvironmentRequest $request): CloudEnvironment
    {
        ++$this->environmentAttempt;
        $this->events->values[] = 'create:environment.' . $request->name;
        $this->environmentApplicationIds[] = $applicationId;
        if ($this->environmentAttempt === $this->failEnvironmentNumber) {
            throw new CloudApiException('Environment creation failed.', 'POST', '/environments', 422);
        }
        return new CloudEnvironment('env-' . $request->name, $applicationId, $request->name, $request->branch);
    }

    public function setEnvironmentVariables(string $environmentId, SetEnvironmentVariablesRequest $request): void
    {
        $this->events->values[] = 'set:variables.' . $environmentId;
    }
}

final readonly class ApplyEnvironmentValueProvider implements EnvironmentValueProvider
{
    public function value(string $name): ?string
    {
        return null;
    }
}

final class ApplyStateStore implements StateStore, StateTransaction
{
    private int $saveNumber = 0;

    public function __construct(
        private readonly ApplyEvents $events,
        public StateDocument $state = new StateDocument(\LaravelCloudBlueprint\State\StateVersion::V1, 0, null),
        private readonly ?int $failSaveNumber = null,
        private readonly bool $failLock = false,
    ) {
    }

    public function load(): StateDocument { return $this->state; }
    public function save(StateDocument $state): StateDocument
    {
        ++$this->saveNumber;
        $resources = $state->resources();
        $last = end($resources);
        $this->events->values[] = 'save:' . ($last instanceof StateResource ? (string) $last->address : 'empty');
        if ($this->saveNumber === $this->failSaveNumber) {
            throw new StateStorageException('checkpoint unavailable');
        }
        $this->state = $state->withSerial($this->state->serial + 1);
        return $this->state;
    }
    public function begin(): StateTransaction
    {
        $this->events->values[] = 'lock';
        if ($this->failLock) { throw new StateLockedException('locked'); }
        return $this;
    }
    public function release(): void { $this->events->values[] = 'release'; }
}
