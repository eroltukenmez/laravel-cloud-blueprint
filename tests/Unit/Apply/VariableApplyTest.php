<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Apply;

use LaravelCloudBlueprint\Apply\ApplyOutcomeOperation;
use LaravelCloudBlueprint\Apply\ApplyResourceOutcome;
use LaravelCloudBlueprint\Apply\ApplyStatus;
use LaravelCloudBlueprint\Apply\CreateOnlyApply;
use LaravelCloudBlueprint\Apply\Exception\ApplyRefusedException;
use LaravelCloudBlueprint\Blueprint\ApplicationDefinition;
use LaravelCloudBlueprint\Blueprint\Blueprint;
use LaravelCloudBlueprint\Blueprint\BlueprintSchemaVersion;
use LaravelCloudBlueprint\Blueprint\EnvironmentDefinition;
use LaravelCloudBlueprint\Blueprint\EnvironmentDefinitionCollection;
use LaravelCloudBlueprint\Blueprint\EnvironmentVariableReference;
use LaravelCloudBlueprint\Blueprint\LiteralVariableValue;
use LaravelCloudBlueprint\Blueprint\SourceDefinition;
use LaravelCloudBlueprint\Blueprint\SourceProvider;
use LaravelCloudBlueprint\Blueprint\VariableDefinition;
use LaravelCloudBlueprint\Blueprint\VariableDefinitionCollection;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudClient;
use LaravelCloudBlueprint\Cloud\DTO\CloudApplication;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironment;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironmentDetails;
use LaravelCloudBlueprint\Cloud\DTO\CloudOrganization;
use LaravelCloudBlueprint\Cloud\DTO\CreateApplicationRequest;
use LaravelCloudBlueprint\Cloud\DTO\CreateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\DTO\SetEnvironmentVariablesRequest;
use LaravelCloudBlueprint\Cloud\DTO\EnvironmentVariableMutationMethod;
use LaravelCloudBlueprint\Cloud\Exception\CloudApiException;
use LaravelCloudBlueprint\Cloud\Exception\CloudTransportException;
use LaravelCloudBlueprint\Planning\Contract\EnvironmentValueProvider;
use LaravelCloudBlueprint\Planning\ExecutionPlan;
use LaravelCloudBlueprint\Planning\PlanAction;
use LaravelCloudBlueprint\Planning\PlanChange;
use LaravelCloudBlueprint\Planning\PlanOperation;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\Planning\VariableValueResolver;
use LaravelCloudBlueprint\State\Contract\StateStore;
use LaravelCloudBlueprint\State\Contract\StateTransaction;
use LaravelCloudBlueprint\State\StateDocument;
use PHPUnit\Framework\TestCase;

final class VariableApplyTest extends TestCase
{
    public function testCreateVariablesAreBatchedPerEnvironmentWithResolvedLiteralAndReferencedValues(): void
    {
        $events = new VariableApplyEvents();
        $cloud = new VariableApplyCloud($events);
        $state = new VariableApplyState($events, StateDocument::empty()->withOrganization('acme')->withSerial(7));
        $blueprint = self::blueprint(
            production: [
                new VariableDefinition('APP_ENV', new LiteralVariableValue('production-runtime'), false),
                new VariableDefinition('APP_KEY', new EnvironmentVariableReference('LOCAL_APP_KEY'), true),
            ],
            staging: [new VariableDefinition('APP_ENV', new LiteralVariableValue('staging-runtime'), false)],
        );
        $plan = new ExecutionPlan(
            self::action(ResourceType::APPLICATION, 'my-api', PlanOperation::NO_CHANGE, 'app-1'),
            self::action(ResourceType::ENVIRONMENT, 'production', PlanOperation::NO_CHANGE, 'env-production'),
            self::action(ResourceType::ENVIRONMENT, 'staging', PlanOperation::NO_CHANGE, 'env-staging'),
            self::action(ResourceType::VARIABLE, 'production.APP_ENV', PlanOperation::CREATE),
            self::action(ResourceType::VARIABLE, 'production.APP_KEY', PlanOperation::CREATE),
            self::action(ResourceType::VARIABLE, 'staging.APP_ENV', PlanOperation::CREATE),
        );

        $result = self::apply(['LOCAL_APP_KEY' => 'resolved-secret'])->execute($blueprint, $plan, $cloud, $state);

        self::assertSame(ApplyStatus::SUCCESS, $result->status);
        self::assertSame(3, $result->createdCount());
        self::assertCount(2, $cloud->variableRequests);
        self::assertSame(['APP_ENV' => 'production-runtime', 'APP_KEY' => 'resolved-secret'], $cloud->requestValues('env-production'));
        self::assertSame(['APP_ENV' => 'staging-runtime'], $cloud->requestValues('env-staging'));
        self::assertSame(['lock', 'set:variables.env-production', 'set:variables.env-staging', 'release'], $events->values);
        self::assertSame(7, $state->state->serial);
        self::assertSame([], $state->state->resources());

        $serialized = serialize(iterator_to_array($result, false));
        self::assertStringNotContainsString('production-runtime', $serialized);
        self::assertStringNotContainsString('resolved-secret', $serialized);
        self::assertStringNotContainsString('staging-runtime', $serialized);
    }

    public function testEnvironmentCreatedInSameApplyProvidesVariableParentIdAfterCheckpoint(): void
    {
        $events = new VariableApplyEvents();
        $cloud = new VariableApplyCloud($events);
        $state = new VariableApplyState($events);
        $blueprint = self::blueprint(
            production: [new VariableDefinition('APP_ENV', new LiteralVariableValue('production'), false)],
        );
        $plan = new ExecutionPlan(
            self::action(ResourceType::APPLICATION, 'my-api', PlanOperation::NO_CHANGE, 'app-1'),
            self::action(ResourceType::ENVIRONMENT, 'production', PlanOperation::CREATE),
            self::action(ResourceType::ENVIRONMENT, 'staging', PlanOperation::NO_CHANGE, 'env-staging'),
            self::action(ResourceType::VARIABLE, 'production.APP_ENV', PlanOperation::CREATE),
        );

        $result = self::apply()->execute($blueprint, $plan, $cloud, $state);

        self::assertSame(ApplyStatus::SUCCESS, $result->status);
        self::assertSame([
            'lock',
            'create:environment.production',
            'save:environment.production',
            'set:variables.env-created-production',
            'release',
        ], $events->values);
        self::assertSame(['APP_ENV' => 'production'], $cloud->requestValues('env-created-production'));
        self::assertNotNull($state->state->find(self::address(ResourceType::ENVIRONMENT, 'production')));
    }

    public function testMissingReferencedValueFailsBeforePostingEnvironmentBatch(): void
    {
        $events = new VariableApplyEvents();
        $cloud = new VariableApplyCloud($events);
        $blueprint = self::blueprint(
            production: [new VariableDefinition('APP_KEY', new EnvironmentVariableReference('LOCAL_APP_KEY'), true)],
        );
        $plan = self::existingVariablePlan('production.APP_KEY', PlanOperation::CREATE);

        $result = self::apply()->execute($blueprint, $plan, $cloud, new VariableApplyState($events));

        self::assertSame(ApplyStatus::FAILED, $result->status);
        self::assertSame([], $cloud->variableRequests);
        self::assertSame(['lock', 'release'], $events->values);
        $outcomes = iterator_to_array($result, false);
        self::assertStringNotContainsString('LOCAL_APP_KEY', $outcomes[2]->message ?? '');
    }

    public function testNoChangeVariablePerformsNoMutation(): void
    {
        $events = new VariableApplyEvents();
        $cloud = new VariableApplyCloud($events);
        $blueprint = self::blueprint(
            production: [new VariableDefinition('APP_ENV', new LiteralVariableValue('production'), false)],
        );

        $result = self::apply()->execute(
            $blueprint,
            self::existingVariablePlan('production.APP_ENV', PlanOperation::NO_CHANGE),
            $cloud,
            new VariableApplyState($events),
        );

        self::assertSame(0, $result->createdCount());
        self::assertSame(3, $result->unchangedCount());
        self::assertSame([], $cloud->variableRequests);
    }

    public function testVariableUpdateUsesSetExistingEnvironmentIdAndDoesNotChangeState(): void
    {
        $events = new VariableApplyEvents();
        $cloud = new VariableApplyCloud($events);
        $initial = StateDocument::empty()->withOrganization('acme')->withSerial(9);
        $state = new VariableApplyState($events, $initial);
        $blueprint = self::blueprint(
            production: [new VariableDefinition('APP_ENV', new LiteralVariableValue('apply-time-value'), true)],
        );

        $result = self::apply()->execute(
            $blueprint,
            self::existingVariablePlan('production.APP_ENV', PlanOperation::UPDATE),
            $cloud,
            $state,
        );

        self::assertSame(ApplyStatus::SUCCESS, $result->status);
        self::assertSame(1, $result->updatedCount());
        self::assertSame(0, $result->createdCount());
        self::assertSame(EnvironmentVariableMutationMethod::SET, $cloud->variableRequests['env-production']->method);
        self::assertSame(['APP_ENV' => 'apply-time-value'], $cloud->requestValues('env-production'));
        self::assertSame(9, $state->state->serial);
        self::assertSame([], $state->state->resources());
        self::assertStringNotContainsString('apply-time-value', serialize($result));
    }

    public function testCreateAndUpdateBatchInBlueprintOrderAndExcludeNoChange(): void
    {
        $events = new VariableApplyEvents();
        $cloud = new VariableApplyCloud($events);
        $blueprint = self::blueprint(production: [
            new VariableDefinition('NEW_KEY', new LiteralVariableValue('new-value'), false),
            new VariableDefinition('APP_ENV', new EnvironmentVariableReference('LOCAL_APP_ENV'), true),
            new VariableDefinition('UNCHANGED', new LiteralVariableValue('same'), false),
        ]);
        $plan = new ExecutionPlan(
            self::action(ResourceType::APPLICATION, 'my-api', PlanOperation::NO_CHANGE, 'app-1'),
            self::action(ResourceType::ENVIRONMENT, 'production', PlanOperation::NO_CHANGE, 'env-production'),
            self::action(ResourceType::VARIABLE, 'production.APP_ENV', PlanOperation::UPDATE),
            self::action(ResourceType::VARIABLE, 'production.NEW_KEY', PlanOperation::CREATE),
            self::action(ResourceType::VARIABLE, 'production.UNCHANGED', PlanOperation::NO_CHANGE),
        );

        $result = self::apply(['LOCAL_APP_ENV' => 'updated-value'])->execute(
            $blueprint,
            $plan,
            $cloud,
            new VariableApplyState($events),
        );

        self::assertSame(['NEW_KEY' => 'new-value', 'APP_ENV' => 'updated-value'], $cloud->requestValues('env-production'));
        self::assertCount(1, $cloud->variableRequests);
        self::assertSame(1, $result->createdCount());
        self::assertSame(1, $result->updatedCount());
        self::assertSame(3, $result->unchangedCount());
    }

    public function testVariableUpdateUnderCreatedEnvironmentUsesCreatedId(): void
    {
        $events = new VariableApplyEvents();
        $cloud = new VariableApplyCloud($events);
        $blueprint = self::blueprint(
            production: [new VariableDefinition('APP_ENV', new LiteralVariableValue('updated'), false)],
        );
        $plan = new ExecutionPlan(
            self::action(ResourceType::APPLICATION, 'my-api', PlanOperation::NO_CHANGE, 'app-1'),
            self::action(ResourceType::ENVIRONMENT, 'production', PlanOperation::CREATE),
            self::action(ResourceType::VARIABLE, 'production.APP_ENV', PlanOperation::UPDATE),
        );

        $result = self::apply()->execute($blueprint, $plan, $cloud, new VariableApplyState($events));

        self::assertSame(1, $result->updatedCount());
        self::assertSame(['APP_ENV' => 'updated'], $cloud->requestValues('env-created-production'));
    }

    public function testMissingReferencedUpdateValuePreventsWholeEnvironmentBatch(): void
    {
        $events = new VariableApplyEvents();
        $cloud = new VariableApplyCloud($events);
        $blueprint = self::blueprint(production: [
            new VariableDefinition('NEW_KEY', new LiteralVariableValue('new-secret'), true),
            new VariableDefinition('APP_KEY', new EnvironmentVariableReference('MISSING_REFERENCE'), true),
        ]);
        $plan = new ExecutionPlan(
            self::action(ResourceType::APPLICATION, 'my-api', PlanOperation::NO_CHANGE, 'app-1'),
            self::action(ResourceType::ENVIRONMENT, 'production', PlanOperation::NO_CHANGE, 'env-production'),
            self::action(ResourceType::VARIABLE, 'production.NEW_KEY', PlanOperation::CREATE),
            self::action(ResourceType::VARIABLE, 'production.APP_KEY', PlanOperation::UPDATE),
        );

        $result = self::apply()->execute($blueprint, $plan, $cloud, new VariableApplyState($events));

        self::assertSame(ApplyStatus::FAILED, $result->status);
        self::assertSame([], $cloud->variableRequests);
        self::assertStringNotContainsString('new-secret', serialize($result));
        self::assertStringNotContainsString('MISSING_REFERENCE', serialize($result));
    }

    public function testEnvironmentUpdateCompletesBeforeVariableUpdate(): void
    {
        $events = new VariableApplyEvents();
        $cloud = new VariableApplyCloud($events);
        $blueprint = self::blueprint(
            production: [new VariableDefinition('APP_ENV', new LiteralVariableValue('updated-value'), false)],
        );
        $plan = new ExecutionPlan(
            self::action(ResourceType::APPLICATION, 'my-api', PlanOperation::NO_CHANGE, 'app-1'),
            self::environmentUpdateAction(),
            self::action(ResourceType::VARIABLE, 'production.APP_ENV', PlanOperation::UPDATE),
        );

        $result = self::apply()->execute($blueprint, $plan, $cloud, new VariableApplyState($events));

        self::assertSame(ApplyStatus::SUCCESS, $result->status);
        self::assertSame(2, $result->updatedCount());
        self::assertSame(['lock', 'update:environment.env-production', 'set:variables.env-production', 'release'], $events->values);
    }

    public function testEnvironmentUpdateFailurePreventsVariablesAndIsFailed(): void
    {
        $events = new VariableApplyEvents();
        $cloud = new VariableApplyCloud($events, failUpdate: true);
        $blueprint = self::blueprint(
            production: [new VariableDefinition('APP_ENV', new LiteralVariableValue('never-sent'), true)],
        );
        $plan = new ExecutionPlan(
            self::environmentUpdateAction(),
            self::action(ResourceType::VARIABLE, 'production.APP_ENV', PlanOperation::UPDATE),
        );

        $result = self::apply()->execute($blueprint, $plan, $cloud, new VariableApplyState($events));

        self::assertSame(ApplyStatus::FAILED, $result->status);
        self::assertSame([], $cloud->variableRequests);
        self::assertSame(['lock', 'update:environment.env-production', 'release'], $events->values);
        self::assertStringNotContainsString('never-sent', serialize($result));
    }

    public function testSuccessfulEnvironmentUpdateThenVariableFailureIsPartial(): void
    {
        $events = new VariableApplyEvents();
        $cloud = new VariableApplyCloud($events, failEnvironmentId: 'env-production');
        $blueprint = self::blueprint(
            production: [new VariableDefinition('APP_ENV', new LiteralVariableValue('rejected-value'), true)],
        );
        $plan = new ExecutionPlan(
            self::environmentUpdateAction(),
            self::action(ResourceType::VARIABLE, 'production.APP_ENV', PlanOperation::UPDATE),
        );

        $result = self::apply()->execute($blueprint, $plan, $cloud, new VariableApplyState($events));

        self::assertSame(ApplyStatus::PARTIAL_FAILURE, $result->status);
        self::assertSame(1, $result->updatedCount());
        self::assertStringNotContainsString('rejected-value', serialize($result));
    }

    public function testAllSupportedUpdatesExecuteInDependencyOrder(): void
    {
        $events = new VariableApplyEvents();
        $cloud = new VariableApplyCloud($events);
        $blueprint = self::blueprint(
            production: [new VariableDefinition('APP_ENV', new LiteralVariableValue('updated-value'), false)],
        );
        $plan = new ExecutionPlan(
            self::applicationUpdateAction(),
            self::environmentUpdateAction(),
            self::action(ResourceType::VARIABLE, 'production.APP_ENV', PlanOperation::UPDATE),
        );

        $result = self::apply()->execute($blueprint, $plan, $cloud, new VariableApplyState($events));

        self::assertSame(ApplyStatus::SUCCESS, $result->status);
        self::assertSame(3, $result->updatedCount());
        self::assertSame([
            'lock',
            'update:application.app-1',
            'update:environment.env-production',
            'set:variables.env-production',
            'release',
        ], $events->values);
    }

    public function testApplicationUpdateEnvironmentCreateAndVariableCreateExecuteInDependencyOrder(): void
    {
        $events = new VariableApplyEvents();
        $cloud = new VariableApplyCloud($events);
        $blueprint = self::blueprint(
            production: [new VariableDefinition('APP_ENV', new LiteralVariableValue('created-value'), false)],
        );
        $plan = new ExecutionPlan(
            self::applicationUpdateAction(),
            self::action(ResourceType::ENVIRONMENT, 'production', PlanOperation::CREATE),
            self::action(ResourceType::VARIABLE, 'production.APP_ENV', PlanOperation::CREATE),
        );

        $result = self::apply()->execute($blueprint, $plan, $cloud, new VariableApplyState($events));

        self::assertSame(ApplyStatus::SUCCESS, $result->status);
        self::assertSame(1, $result->updatedCount());
        self::assertSame(2, $result->createdCount());
        self::assertSame([
            'lock',
            'update:application.app-1',
            'create:environment.production',
            'save:environment.production',
            'set:variables.env-created-production',
            'release',
        ], $events->values);
    }

    public function testUnsupportedActionBlocksAllSupportedUpdatesBeforeLock(): void
    {
        $events = new VariableApplyEvents();
        $plan = new ExecutionPlan(
            self::applicationUpdateAction(),
            self::environmentUpdateAction(),
            self::action(ResourceType::VARIABLE, 'production.APP_ENV', PlanOperation::UPDATE),
            self::action(ResourceType::APPLICATION, 'blocked', PlanOperation::UNSUPPORTED),
        );

        $this->expectException(ApplyRefusedException::class);
        try {
            self::apply()->execute(self::blueprint(), $plan, new VariableApplyCloud($events), new VariableApplyState($events));
        } finally {
            self::assertSame([], $events->values);
        }
    }

    public function testApplicationUpdateFailurePreventsDownstreamMutations(): void
    {
        $events = new VariableApplyEvents();
        $cloud = new VariableApplyCloud($events, failApplicationUpdate: true);
        $blueprint = self::blueprint(
            production: [new VariableDefinition('APP_ENV', new LiteralVariableValue('never-sent'), true)],
        );
        $plan = new ExecutionPlan(
            self::applicationUpdateAction(),
            self::environmentUpdateAction(),
            self::action(ResourceType::VARIABLE, 'production.APP_ENV', PlanOperation::UPDATE),
        );

        $result = self::apply()->execute($blueprint, $plan, $cloud, new VariableApplyState($events));

        self::assertSame(ApplyStatus::FAILED, $result->status);
        self::assertSame(['lock', 'update:application.app-1', 'release'], $events->values);
        self::assertSame([], $cloud->variableRequests);
    }

    public function testApplicationSuccessThenEnvironmentFailureIsPartial(): void
    {
        $events = new VariableApplyEvents();
        $cloud = new VariableApplyCloud($events, failUpdate: true);
        $plan = new ExecutionPlan(self::applicationUpdateAction(), self::environmentUpdateAction());

        $result = self::apply()->execute(
            self::blueprint(),
            $plan,
            $cloud,
            new VariableApplyState($events),
        );

        self::assertSame(ApplyStatus::PARTIAL_FAILURE, $result->status);
        self::assertSame(1, $result->updatedCount());
    }

    public function testUnsupportedVariableRefusesBeforeLockOrAnyMutation(): void
    {
        $events = new VariableApplyEvents();
        $blueprint = self::blueprint(
            production: [new VariableDefinition('APP_ENV', new LiteralVariableValue('production'), false)],
        );

        $this->expectException(ApplyRefusedException::class);
        try {
            self::apply()->execute(
                $blueprint,
                self::existingVariablePlan('production.APP_ENV', PlanOperation::UNSUPPORTED),
                new VariableApplyCloud($events),
                new VariableApplyState($events),
            );
        } finally {
            self::assertSame([], $events->values);
        }
    }

    public function testVariableFailureAfterEnvironmentCreateIsPartialAndKeepsEnvironmentState(): void
    {
        $events = new VariableApplyEvents();
        $cloud = new VariableApplyCloud($events, failEnvironmentId: 'env-created-production');
        $state = new VariableApplyState($events);
        $blueprint = self::blueprint(
            production: [new VariableDefinition('APP_KEY', new LiteralVariableValue('secret-value'), true)],
        );
        $plan = new ExecutionPlan(
            self::action(ResourceType::APPLICATION, 'my-api', PlanOperation::NO_CHANGE, 'app-1'),
            self::action(ResourceType::ENVIRONMENT, 'production', PlanOperation::CREATE),
            self::action(ResourceType::ENVIRONMENT, 'staging', PlanOperation::NO_CHANGE, 'env-staging'),
            self::action(ResourceType::VARIABLE, 'production.APP_KEY', PlanOperation::CREATE),
        );

        $result = self::apply()->execute($blueprint, $plan, $cloud, $state);

        self::assertSame(ApplyStatus::PARTIAL_FAILURE, $result->status);
        self::assertNotNull($state->state->find(self::address(ResourceType::ENVIRONMENT, 'production')));
        self::assertSame(1, $state->state->serial);
        self::assertStringNotContainsString('secret-value', serialize($result));
    }

    public function testUncertainTransportFailureIsPartialWithoutRetryOrSecretExposure(): void
    {
        $events = new VariableApplyEvents();
        $cloud = new VariableApplyCloud($events, transportFailure: true);
        $blueprint = self::blueprint(
            production: [new VariableDefinition('APP_KEY', new LiteralVariableValue('transport-secret'), true)],
        );

        $result = self::apply()->execute(
            $blueprint,
            self::existingVariablePlan('production.APP_KEY', PlanOperation::CREATE),
            $cloud,
            new VariableApplyState($events),
        );

        self::assertSame(ApplyStatus::PARTIAL_FAILURE, $result->status);
        self::assertCount(1, $cloud->variableRequests);
        self::assertStringNotContainsString('transport-secret', serialize($result));
    }

    /** @param array<string, string> $values */
    private static function apply(array $values = []): CreateOnlyApply
    {
        return new CreateOnlyApply(new VariableValueResolver(new VariableApplyEnvironment($values)));
    }

    private static function existingVariablePlan(string $name, PlanOperation $operation): ExecutionPlan
    {
        return new ExecutionPlan(
            self::action(ResourceType::APPLICATION, 'my-api', PlanOperation::NO_CHANGE, 'app-1'),
            self::action(ResourceType::ENVIRONMENT, 'production', PlanOperation::NO_CHANGE, 'env-production'),
            self::action(ResourceType::VARIABLE, $name, $operation),
        );
    }

    /**
     * @param list<VariableDefinition> $production
     * @param list<VariableDefinition> $staging
     */
    private static function blueprint(array $production = [], array $staging = []): Blueprint
    {
        return new Blueprint(
            BlueprintSchemaVersion::V1,
            'acme',
            new ApplicationDefinition('my-api', 'eu-central-1', new SourceDefinition(SourceProvider::GITHUB, 'acme/my-api')),
            new EnvironmentDefinitionCollection(
                new EnvironmentDefinition('production', 'main', new VariableDefinitionCollection(...$production)),
                new EnvironmentDefinition('staging', 'develop', new VariableDefinitionCollection(...$staging)),
            ),
        );
    }

    private static function action(
        ResourceType $type,
        string $name,
        PlanOperation $operation,
        ?string $remoteId = null,
    ): PlanAction {
        return new PlanAction(self::address($type, $name), $type, $operation, 'safe reason', $remoteId);
    }

    private static function environmentUpdateAction(): PlanAction
    {
        return new PlanAction(
            self::address(ResourceType::ENVIRONMENT, 'production'),
            ResourceType::ENVIRONMENT,
            PlanOperation::UPDATE,
            'test',
            'env-production',
            new PlanChange('branch', 'develop', 'main'),
        );
    }

    private static function applicationUpdateAction(): PlanAction
    {
        return new PlanAction(
            self::address(ResourceType::APPLICATION, 'my-api'),
            ResourceType::APPLICATION,
            PlanOperation::UPDATE,
            'test',
            'app-1',
            new PlanChange('repository', 'acme/old-api', 'acme/my-api'),
        );
    }

    private static function address(ResourceType $type, string $name): ResourceAddress
    {
        return new ResourceAddress($type, $name);
    }
}

final class VariableApplyEvents
{
    /** @var list<string> */
    public array $values = [];
}

final readonly class VariableApplyEnvironment implements EnvironmentValueProvider
{
    /** @param array<string, string> $values */
    public function __construct(private array $values)
    {
    }

    public function value(string $name): ?string
    {
        return $this->values[$name] ?? null;
    }
}

final class VariableApplyCloud implements LaravelCloudClient
{
    public function updateApplication(string $applicationId, \LaravelCloudBlueprint\Cloud\DTO\UpdateApplicationRequest $request): CloudApplication
    {
        $this->events->values[] = 'update:application.' . $applicationId;
        if ($this->failApplicationUpdate) {
            throw new CloudApiException('Application update failed.', 'PATCH', '/applications/' . $applicationId, 422);
        }
        return new CloudApplication($applicationId, 'my-api', 'my-api', 'eu-central-1', $request->repository, $request->sourceProvider);
    }
    public function updateEnvironment(string $environmentId, \LaravelCloudBlueprint\Cloud\DTO\UpdateEnvironmentRequest $request): \LaravelCloudBlueprint\Cloud\DTO\UpdatedCloudEnvironment
    {
        $this->events->values[] = 'update:environment.' . $environmentId;
        if ($this->failUpdate) {
            throw new CloudApiException('Environment update failed.', 'PATCH', '/environments/' . $environmentId, 422);
        }
        return new \LaravelCloudBlueprint\Cloud\DTO\UpdatedCloudEnvironment($environmentId);
    }
    /** @var array<string, SetEnvironmentVariablesRequest> */
    public array $variableRequests = [];

    public function __construct(
        private readonly VariableApplyEvents $events,
        private readonly ?string $failEnvironmentId = null,
        private readonly bool $transportFailure = false,
        private readonly bool $failUpdate = false,
        private readonly bool $failApplicationUpdate = false,
    ) {
    }

    public function organization(): CloudOrganization { return new CloudOrganization('org-1', 'Acme', 'acme'); }
    public function applications(): array { return []; }
    public function environments(string $applicationId): array { return []; }
    public function environment(string $environmentId): CloudEnvironmentDetails
    {
        return new CloudEnvironmentDetails($environmentId, 'unused', null);
    }
    public function createApplication(CreateApplicationRequest $request): CloudApplication
    {
        $this->events->values[] = 'create:application';
        return new CloudApplication('app-created', $request->name, null, $request->region, $request->repository);
    }
    public function createEnvironment(string $applicationId, CreateEnvironmentRequest $request): CloudEnvironment
    {
        $this->events->values[] = 'create:environment.' . $request->name;
        return new CloudEnvironment('env-created-' . $request->name, $applicationId, $request->name, $request->branch);
    }
    public function setEnvironmentVariables(string $environmentId, SetEnvironmentVariablesRequest $request): void
    {
        $this->events->values[] = 'set:variables.' . $environmentId;
        $this->variableRequests[$environmentId] = $request;
        if ($this->transportFailure) {
            throw new CloudTransportException('Variable outcome is uncertain. Run plan before retrying.', 'POST', '/variables');
        }
        if ($environmentId === $this->failEnvironmentId) {
            throw new CloudApiException('Variable request failed.', 'POST', '/variables', 422);
        }
    }

    /** @return array<string, string> */
    public function requestValues(string $environmentId): array
    {
        $values = [];
        foreach ($this->variableRequests[$environmentId]->variables() as $variable) {
            $values[$variable->key] = $variable->value;
        }
        return $values;
    }
}

final class VariableApplyState implements StateStore, StateTransaction
{
    public function __construct(
        private readonly VariableApplyEvents $events,
        public StateDocument $state = new StateDocument(\LaravelCloudBlueprint\State\StateVersion::V1, 0, null),
    ) {
    }

    public function load(): StateDocument { return $this->state; }
    public function save(StateDocument $state): StateDocument
    {
        $resources = $state->resources();
        $last = end($resources);
        $this->events->values[] = 'save:' . ($last instanceof \LaravelCloudBlueprint\State\StateResource
            ? (string) $last->address
            : 'empty');
        $this->state = $state->withSerial($this->state->serial + 1);
        return $this->state;
    }
    public function begin(): StateTransaction
    {
        $this->events->values[] = 'lock';
        return $this;
    }
    public function release(): void { $this->events->values[] = 'release'; }
}
