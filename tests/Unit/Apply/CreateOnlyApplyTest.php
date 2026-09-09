<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Apply;

use LaravelCloudBlueprint\Apply\ApplyOutcome;
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
use LaravelCloudBlueprint\Cloud\Exception\CloudResponseException;
use LaravelCloudBlueprint\Planning\ExecutionPlan;
use LaravelCloudBlueprint\Planning\Contract\EnvironmentValueProvider;
use LaravelCloudBlueprint\Planning\PlanAction;
use LaravelCloudBlueprint\Planning\PlanChange;
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
        foreach ($result as $outcome) {
            self::assertSame(ApplyOutcome::CREATED, $outcome->outcome);
        }
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
        $outcomes = iterator_to_array($result);
        self::assertSame(ApplyOutcome::UNCHANGED, $outcomes[0]->outcome);
        self::assertSame(ApplyOutcome::CREATED, $outcomes[1]->outcome);
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
        self::assertSame(ApplyOutcome::UNCHANGED, iterator_to_array($result)[0]->outcome);
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

    public function testEnvironmentDeleteWithoutDependencyDiscoveryRefusesBeforeLockOrMutation(): void
    {
        $events = new ApplyEvents();
        $application = self::address(ResourceType::APPLICATION, 'my-api');
        $plan = new ExecutionPlan(new PlanAction(
            self::address(ResourceType::ENVIRONMENT, 'preview'),
            ResourceType::ENVIRONMENT,
            PlanOperation::DELETE,
            'Dependency discovery is unavailable.',
            'env-preview',
            $application,
        ));
        $states = new ApplyStateStore($events);

        try {
            self::apply()->execute(self::blueprint(), $plan, new ApplyCloudClient($events), $states);
            self::fail('Expected DELETE refusal.');
        } catch (ApplyRefusedException $exception) {
            self::assertStringContainsString('dependency discovery is unavailable', $exception->getMessage());
        }

        self::assertSame([], $events->values);
        self::assertSame(0, $states->state->serial);
    }

    public function testApplicationUpdateIsRefusedBeforeLockOrMutation(): void
    {
        $events = new ApplyEvents();
        $plan = new ExecutionPlan(new PlanAction(
            self::address(ResourceType::APPLICATION, 'my-api'),
            ResourceType::APPLICATION,
            PlanOperation::UPDATE,
            'test',
            'app-existing',
            new PlanChange('repository', 'old', 'new'),
        ));

        $this->expectException(ApplyRefusedException::class);
        try {
            self::apply()->execute(self::blueprint(), $plan, new ApplyCloudClient($events), new ApplyStateStore($events));
        } finally {
            self::assertSame([], $events->values);
        }
    }

    public function testEnvironmentBranchUpdateIsAcceptedWithoutSavingState(): void
    {
        $events = new ApplyEvents();
        $application = self::address(ResourceType::APPLICATION, 'my-api');
        $states = new ApplyStateStore($events, StateDocument::empty()->withOrganization('acme')
            ->withResource(new StateResource($application, ResourceType::APPLICATION, 'app-existing'))
            ->withResource(new StateResource(
                self::address(ResourceType::ENVIRONMENT, 'production'),
                ResourceType::ENVIRONMENT,
                'env-existing',
                $application,
            ))
            ->withSerial(4));
        $plan = new ExecutionPlan(new PlanAction(
            self::address(ResourceType::ENVIRONMENT, 'production'),
            ResourceType::ENVIRONMENT,
            PlanOperation::UPDATE,
            'test',
            'env-existing',
            new PlanChange('branch', 'develop', 'main'),
        ));

        $result = self::apply()->execute(self::blueprint(), $plan, new ApplyCloudClient($events), $states);

        self::assertSame(ApplyStatus::SUCCESS, $result->status);
        self::assertSame(1, $result->updatedCount());
        self::assertSame(['lock', 'update:environment.env-existing:main', 'release'], $events->values);
        self::assertSame(4, $states->state->serial);
        self::assertCount(2, $states->state->resources());
    }

    public function testUnsupportedEnvironmentUpdateFieldsRefuseBeforeLockOrMutation(): void
    {
        $events = new ApplyEvents();
        $plan = new ExecutionPlan(new PlanAction(
            self::address(ResourceType::ENVIRONMENT, 'production'),
            ResourceType::ENVIRONMENT,
            PlanOperation::UPDATE,
            'test',
            'env-existing',
            new PlanChange('name', 'production', 'renamed'),
            new PlanChange('branch', 'develop', 'main'),
        ));

        try {
            self::apply()->execute(self::blueprint(), $plan, new ApplyCloudClient($events), new ApplyStateStore($events));
            self::fail('Expected unsupported environment field refusal.');
        } catch (ApplyRefusedException $exception) {
            self::assertStringContainsString('unsupported field changes', $exception->getMessage());
        }

        self::assertSame([], $events->values);
    }

    public function testSingleUnknownEnvironmentUpdateFieldRefusesBeforeLock(): void
    {
        $events = new ApplyEvents();
        $plan = new ExecutionPlan(new PlanAction(
            self::address(ResourceType::ENVIRONMENT, 'production'),
            ResourceType::ENVIRONMENT,
            PlanOperation::UPDATE,
            'test',
            'env-existing',
            new PlanChange('name', 'production', 'renamed'),
        ));

        $this->expectException(ApplyRefusedException::class);
        try {
            self::apply()->execute(self::blueprint(), $plan, new ApplyCloudClient($events), new ApplyStateStore($events));
        } finally {
            self::assertSame([], $events->values);
        }
    }

    public function testEnvironmentCreateAndUpdateCollisionRefusesBeforeLock(): void
    {
        $events = new ApplyEvents();
        $plan = new ExecutionPlan(
            self::action(ResourceType::ENVIRONMENT, 'production', PlanOperation::CREATE),
            new PlanAction(
                self::address(ResourceType::ENVIRONMENT, 'production'),
                ResourceType::ENVIRONMENT,
                PlanOperation::UPDATE,
                'test',
                'env-existing',
                new PlanChange('branch', 'develop', 'main'),
            ),
        );

        $this->expectException(ApplyRefusedException::class);
        try {
            self::apply()->execute(self::blueprint(), $plan, new ApplyCloudClient($events), new ApplyStateStore($events));
        } finally {
            self::assertSame([], $events->values);
        }
    }

    public function testMatchingManagedEnvironmentIdentityAllowsUpdateWithoutStateSave(): void
    {
        $events = new ApplyEvents();
        $managed = new StateResource(
            self::address(ResourceType::ENVIRONMENT, 'production'),
            ResourceType::ENVIRONMENT,
            'env-managed',
            self::address(ResourceType::APPLICATION, 'my-api'),
        );
        $state = StateDocument::empty()->withOrganization('acme')
            ->withResource(new StateResource(
                self::address(ResourceType::APPLICATION, 'my-api'),
                ResourceType::APPLICATION,
                'app-managed',
            ))
            ->withResource($managed)
            ->withSerial(3);
        $plan = new ExecutionPlan(new PlanAction(
            self::address(ResourceType::ENVIRONMENT, 'production'),
            ResourceType::ENVIRONMENT,
            PlanOperation::UPDATE,
            'test',
            'env-managed',
            new PlanChange('branch', 'develop', 'main'),
        ));

        $result = self::apply()->execute(
            self::blueprint(),
            $plan,
            new ApplyCloudClient($events),
            new ApplyStateStore($events, $state),
        );

        self::assertSame(ApplyStatus::SUCCESS, $result->status);
        self::assertSame(['lock', 'update:environment.env-managed:main', 'release'], $events->values);
    }

    public function testEnvironmentUpdateStateIdentityConflictAbortsBeforePatch(): void
    {
        $events = new ApplyEvents();
        $application = self::address(ResourceType::APPLICATION, 'my-api');
        $state = StateDocument::empty()->withOrganization('acme')
            ->withResource(new StateResource($application, ResourceType::APPLICATION, 'app-existing'))
            ->withResource(new StateResource(
                self::address(ResourceType::ENVIRONMENT, 'production'),
                ResourceType::ENVIRONMENT,
                'env-managed',
                $application,
            ));
        $plan = new ExecutionPlan(new PlanAction(
            self::address(ResourceType::ENVIRONMENT, 'production'),
            ResourceType::ENVIRONMENT,
            PlanOperation::UPDATE,
            'test',
            'env-other',
            new PlanChange('branch', 'develop', 'main'),
        ));

        try {
            self::apply()->execute(self::blueprint(), $plan, new ApplyCloudClient($events), new ApplyStateStore($events, $state));
            self::fail('Expected state identity conflict.');
        } catch (StateIdentityConflictException) {
        }

        self::assertSame(['lock', 'release'], $events->values);
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
        self::assertSame(ApplyOutcome::REFUSED, iterator_to_array($result)[0]->outcome);
        self::assertSame([], $states->state->resources());
        self::assertSame(['lock', 'create:application', 'release'], $events->values);
    }

    public function testApplicationCheckpointFailureStopsBeforeEnvironmentAndIsPartial(): void
    {
        $events = new ApplyEvents();
        $states = new ApplyStateStore($events, failSaveNumber: 1);

        $result = self::apply()->execute(self::blueprint(), self::createPlan(), new ApplyCloudClient($events), $states);

        self::assertSame(ApplyStatus::PARTIAL_FAILURE, $result->status);
        self::assertSame(ApplyOutcome::STATE_CHECKPOINT_FAILED, iterator_to_array($result)[0]->outcome);
        self::assertSame(['lock', 'create:application', 'save:application.my-api', 'release'], $events->values);
    }

    public function testIncompatibleApplicationCreateResponseIsNotCheckpointedOrRetried(): void
    {
        $events = new ApplyEvents();
        $states = new ApplyStateStore($events);
        $cloud = new ApplyCloudClient(
            $events,
            applicationResponse: new CloudApplication('app-unexpected', 'other-api', null, 'eu-central-1', 'acme/my-api'),
        );

        $result = self::apply()->execute(self::blueprint(), self::createPlan(), $cloud, $states);

        self::assertSame(ApplyStatus::PARTIAL_FAILURE, $result->status);
        self::assertSame(ApplyOutcome::CONFLICT, iterator_to_array($result)[0]->outcome);
        self::assertSame(0, $states->state->serial);
        self::assertSame([], $states->state->resources());
        self::assertSame(['lock', 'create:application', 'release'], $events->values);
        $message = iterator_to_array($result)[0]->message;
        self::assertNotNull($message);
        self::assertStringContainsString('may have succeeded remotely', $message);
        self::assertStringContainsString('refused to record ownership', $message);
        self::assertStringNotContainsString('app-unexpected', $message);
    }

    public function testIncompatibleApplicationRepositoryRegionOrProviderIsNotCheckpointed(): void
    {
        $cases = [
            new CloudApplication('app-created', 'my-api', null, 'eu-central-1', 'other/api'),
            new CloudApplication('app-created', 'my-api', null, 'us-east-1', 'acme/my-api'),
            new CloudApplication('app-created', 'my-api', null, 'eu-central-1', 'acme/my-api', SourceProvider::GITLAB),
            new CloudApplication('', 'my-api', null, 'eu-central-1', 'acme/my-api'),
        ];

        foreach ($cases as $index => $response) {
            $events = new ApplyEvents();
            $states = new ApplyStateStore($events);

            $result = self::apply()->execute(
                self::blueprint(),
                new ExecutionPlan(self::action(ResourceType::APPLICATION, 'my-api', PlanOperation::CREATE)),
                new ApplyCloudClient($events, applicationResponse: $response),
                $states,
            );

            self::assertSame(ApplyStatus::PARTIAL_FAILURE, $result->status);
            self::assertSame(
                $index === 3 ? ApplyOutcome::POSTCONDITION_FAILED : ApplyOutcome::CONFLICT,
                iterator_to_array($result)[0]->outcome,
            );
            self::assertSame(0, $states->state->serial);
            self::assertSame([], $states->state->resources());
            self::assertSame(['lock', 'create:application', 'release'], $events->values);
        }
    }

    public function testMalformedApplicationCreateResponseIsAnUncheckpointedPostconditionFailure(): void
    {
        $events = new ApplyEvents();
        $states = new ApplyStateStore($events);

        $result = self::apply()->execute(
            self::blueprint(),
            self::createPlan(),
            new ApplyCloudClient($events, malformedApplicationResponse: true),
            $states,
        );

        self::assertSame(ApplyStatus::PARTIAL_FAILURE, $result->status);
        self::assertSame(ApplyOutcome::POSTCONDITION_FAILED, iterator_to_array($result)[0]->outcome);
        self::assertSame(0, $states->state->serial);
        self::assertSame([], $states->state->resources());
        self::assertSame(['lock', 'create:application', 'release'], $events->values);
        $message = iterator_to_array($result)[0]->message;
        self::assertNotNull($message);
        self::assertStringContainsString('may have succeeded remotely', $message);
        self::assertStringNotContainsString('unsafe raw application payload', $message);
    }

    public function testApplicationRemainsCheckpointedWhenEnvironmentFails(): void
    {
        $events = new ApplyEvents();
        $states = new ApplyStateStore($events);
        $cloud = new ApplyCloudClient($events, failEnvironmentNumber: 1);

        $result = self::apply()->execute(self::blueprint(), self::createPlan(), $cloud, $states);

        self::assertSame(ApplyStatus::PARTIAL_FAILURE, $result->status);
        self::assertSame(ApplyOutcome::REFUSED, iterator_to_array($result)[1]->outcome);
        self::assertNotNull($states->state->find(self::address(ResourceType::APPLICATION, 'my-api')));
        self::assertNull($states->state->find(self::address(ResourceType::ENVIRONMENT, 'production')));
    }

    public function testIncompatibleEnvironmentCreateResponseIsNotCheckpointedOrRetriedAndStopsLaterWork(): void
    {
        $events = new ApplyEvents();
        $states = new ApplyStateStore($events);
        $cloud = new ApplyCloudClient(
            $events,
            environmentResponse: new CloudEnvironment('env-unexpected', 'app-created', 'other-environment', 'main'),
        );

        $result = self::apply()->execute(self::blueprint(), self::createPlan(), $cloud, $states);

        self::assertSame(ApplyStatus::PARTIAL_FAILURE, $result->status);
        self::assertSame(ApplyOutcome::CONFLICT, iterator_to_array($result)[1]->outcome);
        self::assertSame(1, $states->state->serial);
        self::assertNotNull($states->state->find(self::address(ResourceType::APPLICATION, 'my-api')));
        self::assertNull($states->state->find(self::address(ResourceType::ENVIRONMENT, 'production')));
        self::assertNull($states->state->find(self::address(ResourceType::ENVIRONMENT, 'staging')));
        self::assertSame([
            'lock', 'create:application', 'save:application.my-api', 'create:environment.production', 'release',
        ], $events->values);
        $message = iterator_to_array($result)[1]->message;
        self::assertNotNull($message);
        self::assertStringContainsString('may have succeeded remotely', $message);
    }

    public function testConflictingEnvironmentResponseParentIsNotCheckpointed(): void
    {
        $events = new ApplyEvents();
        $application = self::address(ResourceType::APPLICATION, 'my-api');
        $states = new ApplyStateStore($events, StateDocument::empty()->withOrganization('acme')
            ->withResource(new StateResource($application, ResourceType::APPLICATION, 'app-existing'))
            ->withSerial(4));
        $cloud = new ApplyCloudClient(
            $events,
            environmentResponse: new CloudEnvironment(
                'env-production',
                'app-existing',
                'production',
                'main',
                responseApplicationId: 'app-other',
                hasResponseApplicationRelationship: true,
            ),
        );
        $plan = new ExecutionPlan(
            self::action(ResourceType::APPLICATION, 'my-api', PlanOperation::NO_CHANGE, 'app-existing'),
            self::action(ResourceType::ENVIRONMENT, 'production', PlanOperation::CREATE),
        );

        $result = self::apply()->execute(self::blueprint(), $plan, $cloud, $states);

        self::assertSame(ApplyStatus::PARTIAL_FAILURE, $result->status);
        self::assertSame(ApplyOutcome::CONFLICT, iterator_to_array($result)[1]->outcome);
        self::assertSame(4, $states->state->serial);
        self::assertNull($states->state->find(self::address(ResourceType::ENVIRONMENT, 'production')));
        self::assertSame(['lock', 'create:environment.production', 'release'], $events->values);
    }

    public function testUnverifiableEnvironmentResponseIdentityIsNotCheckpointed(): void
    {
        $events = new ApplyEvents();
        $application = self::address(ResourceType::APPLICATION, 'my-api');
        $states = new ApplyStateStore($events, StateDocument::empty()->withOrganization('acme')
            ->withResource(new StateResource($application, ResourceType::APPLICATION, 'app-existing'))
            ->withSerial(4));
        $cloud = new ApplyCloudClient(
            $events,
            environmentResponse: new CloudEnvironment('', 'app-existing', 'production', 'main'),
        );
        $plan = new ExecutionPlan(
            self::action(ResourceType::APPLICATION, 'my-api', PlanOperation::NO_CHANGE, 'app-existing'),
            self::action(ResourceType::ENVIRONMENT, 'production', PlanOperation::CREATE),
        );

        $result = self::apply()->execute(self::blueprint(), $plan, $cloud, $states);

        self::assertSame(ApplyStatus::PARTIAL_FAILURE, $result->status);
        self::assertSame(ApplyOutcome::POSTCONDITION_FAILED, iterator_to_array($result)[1]->outcome);
        self::assertSame(4, $states->state->serial);
        self::assertNull($states->state->find(self::address(ResourceType::ENVIRONMENT, 'production')));
        self::assertSame(['lock', 'create:environment.production', 'release'], $events->values);
    }

    public function testFirstEnvironmentRemainsCheckpointedWhenSecondFails(): void
    {
        $events = new ApplyEvents();
        $states = new ApplyStateStore($events);
        $cloud = new ApplyCloudClient($events, failEnvironmentNumber: 2);

        $result = self::apply()->execute(self::blueprint(), self::createPlan(), $cloud, $states);

        self::assertSame(ApplyStatus::PARTIAL_FAILURE, $result->status);
        self::assertSame(ApplyOutcome::REFUSED, iterator_to_array($result)[2]->outcome);
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
    public function updateEnvironment(string $environmentId, \LaravelCloudBlueprint\Cloud\DTO\UpdateEnvironmentRequest $request): \LaravelCloudBlueprint\Cloud\DTO\UpdatedCloudEnvironment
    {
        $this->events->values[] = 'update:environment.' . $environmentId . ':' . $request->branch;
        return new \LaravelCloudBlueprint\Cloud\DTO\UpdatedCloudEnvironment($environmentId);
    }
    /** @var list<string> */
    public array $environmentApplicationIds = [];
    private int $environmentAttempt = 0;

    public function __construct(
        private readonly ApplyEvents $events,
        private readonly bool $failApplication = false,
        private readonly ?int $failEnvironmentNumber = null,
        private readonly ?CloudApplication $applicationResponse = null,
        private readonly ?CloudEnvironment $environmentResponse = null,
        private readonly bool $malformedApplicationResponse = false,
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
        if ($this->malformedApplicationResponse) {
            throw new CloudResponseException('unsafe raw application payload', 'POST', '/applications');
        }
        return $this->applicationResponse
            ?? new CloudApplication('app-created', $request->name, $request->name, $request->region, $request->repository);
    }

    public function createEnvironment(string $applicationId, CreateEnvironmentRequest $request): CloudEnvironment
    {
        ++$this->environmentAttempt;
        $this->events->values[] = 'create:environment.' . $request->name;
        $this->environmentApplicationIds[] = $applicationId;
        if ($this->environmentAttempt === $this->failEnvironmentNumber) {
            throw new CloudApiException('Environment creation failed.', 'POST', '/environments', 422);
        }
        return $this->environmentResponse
            ?? new CloudEnvironment('env-' . $request->name, $applicationId, $request->name, $request->branch);
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
