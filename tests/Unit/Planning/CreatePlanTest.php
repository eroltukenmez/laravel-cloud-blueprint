<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Planning;

use LaravelCloudBlueprint\Blueprint\ApplicationDefinition;
use LaravelCloudBlueprint\Blueprint\Blueprint;
use LaravelCloudBlueprint\Blueprint\BlueprintSchemaVersion;
use LaravelCloudBlueprint\Blueprint\EnvironmentDefinition;
use LaravelCloudBlueprint\Blueprint\EnvironmentDefinitionCollection;
use LaravelCloudBlueprint\Blueprint\SourceDefinition;
use LaravelCloudBlueprint\Blueprint\SourceProvider;
use LaravelCloudBlueprint\Blueprint\VariableDefinitionCollection;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironmentDetails;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudClient;
use LaravelCloudBlueprint\Cloud\DTO\CloudApplication;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironment;
use LaravelCloudBlueprint\Cloud\DTO\CloudOrganization;
use LaravelCloudBlueprint\Cloud\DTO\CreateApplicationRequest;
use LaravelCloudBlueprint\Cloud\DTO\CreateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\DTO\SetEnvironmentVariablesRequest;
use LogicException;
use LaravelCloudBlueprint\Planning\CreatePlan;
use LaravelCloudBlueprint\Planning\Contract\EnvironmentValueProvider;
use LaravelCloudBlueprint\Planning\Exception\AmbiguousResourceMatchException;
use LaravelCloudBlueprint\Planning\Exception\OrganizationMismatchException;
use LaravelCloudBlueprint\Planning\ExecutionPlan;
use LaravelCloudBlueprint\Planning\PlanAction;
use LaravelCloudBlueprint\Planning\PlanOperation;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\Planning\VariableValueResolver;
use LaravelCloudBlueprint\State\StateDocument;
use LaravelCloudBlueprint\State\StateResource;
use PHPUnit\Framework\TestCase;

final class CreatePlanTest extends TestCase
{
    public function testOrganizationMustMatchByExactSlug(): void
    {
        $cloud = new PlanningCloudClient('wrong-organization');

        $this->expectException(OrganizationMismatchException::class);

        self::planner()->create(self::blueprint(), $cloud, StateDocument::empty());
    }

    public function testMissingApplicationCreatesApplicationAndEveryEnvironmentWithoutEnvironmentLookup(): void
    {
        $cloud = new PlanningCloudClient();

        $plan = self::planner()->create(self::blueprint(), $cloud, StateDocument::empty());

        self::assertSame(
            ['application.my-api', 'environment.production', 'environment.staging'],
            self::addresses($plan),
        );
        self::assertSame(3, $plan->countByOperation(PlanOperation::CREATE));
        self::assertTrue($plan->hasActionableChanges());
        self::assertSame(['organization', 'applications'], $cloud->calls);
    }

    public function testMatchingApplicationAndEnvironmentProduceNoChangesAndIgnoreExtras(): void
    {
        $cloud = new PlanningCloudClient(
            applications: [
                self::remoteApplication(),
                new CloudApplication('app-extra', 'unmanaged', null, 'other', null),
            ],
            environments: [
                new CloudEnvironment('env-prod', 'app-1', 'production', 'main'),
                new CloudEnvironment('env-stage', 'app-1', 'staging', 'develop'),
                new CloudEnvironment('env-extra', 'app-1', 'preview', 'feature'),
            ],
        );

        $plan = self::planner()->create(self::blueprint(), $cloud, StateDocument::empty());

        self::assertSame(3, $plan->countByOperation(PlanOperation::NO_CHANGE));
        self::assertSame(0, $plan->countByOperation(PlanOperation::CREATE));
        self::assertSame(0, $plan->countByOperation(PlanOperation::UPDATE));
        self::assertSame(['organization', 'applications', 'environments:app-1'], $cloud->calls);
    }

    public function testApplicationRegionDifferenceIsUnsupported(): void
    {
        $plan = $this->planWithApplication(self::remoteApplication(region: 'us-east-1'));

        self::assertSame(PlanOperation::UNSUPPORTED, self::actions($plan)[0]->operation);
        self::assertStringContainsString('region differs', self::actions($plan)[0]->reason);
    }

    public function testApplicationRepositoryDifferenceIsUnsupportedWithoutAChangePayload(): void
    {
        $plan = $this->planWithApplication(self::remoteApplication(repository: 'other/repository'));

        $action = self::actions($plan)[0];
        self::assertSame(PlanOperation::UNSUPPORTED, $action->operation);
        self::assertSame('Application repository differs and cannot be updated safely.', $action->reason);
        self::assertSame([], $action->changes);
        self::assertSame(0, $plan->countByOperation(PlanOperation::UPDATE));
    }

    public function testApplicationRegionAndRepositoryDifferenceRemainsUnsupportedWithoutChanges(): void
    {
        $action = self::actions($this->planWithApplication(
            self::remoteApplication(region: 'us-east-1', repository: 'other/repository'),
        ))[0];

        self::assertSame(PlanOperation::UNSUPPORTED, $action->operation);
        self::assertSame([], $action->changes);
    }

    public function testUnavailableApplicationRepositoryIsUnsupported(): void
    {
        $plan = $this->planWithApplication(self::remoteApplication(repository: null));

        self::assertSame(PlanOperation::UNSUPPORTED, self::actions($plan)[0]->operation);
    }

    public function testDuplicateApplicationNamesFailSafely(): void
    {
        $cloud = new PlanningCloudClient(applications: [self::remoteApplication(), self::remoteApplication('app-2')]);

        $this->expectException(AmbiguousResourceMatchException::class);

        self::planner()->create(self::blueprint(), $cloud, StateDocument::empty());
    }

    public function testEnvironmentMatchingProducesCreateNoChangeAndUpdateInBlueprintOrder(): void
    {
        $cloud = new PlanningCloudClient(
            applications: [self::remoteApplication()],
            environments: [new CloudEnvironment('env-prod', 'app-1', 'production', 'other')],
        );

        $actions = self::actions(self::planner()->create(self::blueprint(), $cloud, self::managedState()));

        self::assertSame(PlanOperation::NO_CHANGE, $actions[0]->operation);
        self::assertSame(PlanOperation::UPDATE, $actions[1]->operation);
        self::assertSame('branch', $actions[1]->changes[0]->field);
        self::assertSame('other', $actions[1]->changes[0]->before);
        self::assertSame('main', $actions[1]->changes[0]->after);
        self::assertSame(PlanOperation::CREATE, $actions[2]->operation);
        self::assertSame(
            ['application.my-api', 'environment.production', 'environment.staging'],
            array_map(static fn (PlanAction $action): string => (string) $action->address, $actions),
        );
    }

    public function testMissingRemoteBranchIsUnsupported(): void
    {
        $cloud = new PlanningCloudClient(
            applications: [self::remoteApplication()],
            environments: [new CloudEnvironment('env-prod', 'app-1', 'production', null)],
        );

        self::assertSame(
            PlanOperation::UNSUPPORTED,
            self::actions(self::planner()->create(self::blueprint(), $cloud, StateDocument::empty()))[1]->operation,
        );
    }

    public function testDuplicateEnvironmentNamesFailSafely(): void
    {
        $cloud = new PlanningCloudClient(
            applications: [self::remoteApplication()],
            environments: [
                new CloudEnvironment('env-1', 'app-1', 'production', 'main'),
                new CloudEnvironment('env-2', 'app-1', 'production', 'main'),
            ],
        );

        $this->expectException(AmbiguousResourceMatchException::class);

        self::planner()->create(self::blueprint(), $cloud, StateDocument::empty());
    }

    public function testManagedApplicationResolvesByRemoteIdInsteadOfNameFallback(): void
    {
        $state = self::managedState();
        $cloud = new PlanningCloudClient(
            applications: [self::remoteApplication('app-replacement')],
            environments: [new CloudEnvironment('env-prod', 'app-replacement', 'production', 'main')],
        );

        $plan = self::planner()->create(self::blueprint(), $cloud, $state);
        $application = self::action($plan, 'application.my-api');

        self::assertSame(PlanOperation::UNSUPPORTED, $application->operation);
        self::assertStringContainsString('same-name unmanaged replacement', $application->reason);
        self::assertSame(0, $plan->countByOperation(PlanOperation::CREATE));
        self::assertSame(0, $plan->countByOperation(PlanOperation::UPDATE));
        self::assertSame(2, count($state->resources()));
    }

    public function testMissingManagedApplicationIsLifecycleDiagnosticNotCreate(): void
    {
        $plan = self::planner()->create(self::blueprint(), new PlanningCloudClient(), self::managedState());

        self::assertSame(PlanOperation::UNSUPPORTED, self::action($plan, 'application.my-api')->operation);
        self::assertStringContainsString('remote identity is missing', self::action($plan, 'application.my-api')->reason);
        self::assertSame(0, $plan->countByOperation(PlanOperation::CREATE));
    }

    public function testManagedApplicationNameDriftIsNonActionable(): void
    {
        $renamed = new CloudApplication('app-1', 'renamed', 'renamed', 'eu-central-1', 'acme/my-api');
        $plan = self::planner()->create(
            self::blueprint(),
            new PlanningCloudClient(applications: [$renamed]),
            self::managedState(),
        );

        self::assertSame(PlanOperation::UNSUPPORTED, self::action($plan, 'application.my-api')->operation);
        self::assertStringContainsString('name differs', self::action($plan, 'application.my-api')->reason);
    }

    public function testDuplicateRemoteIdentityOwnershipBlocksPlanning(): void
    {
        $application = new ResourceAddress(ResourceType::APPLICATION, 'my-api');
        $state = self::managedState()->withResource(new StateResource(
            new ResourceAddress(ResourceType::ENVIRONMENT, 'duplicate'),
            ResourceType::ENVIRONMENT,
            'app-1',
            $application,
        ));
        $plan = self::planner()->create(
            self::blueprint(),
            new PlanningCloudClient(applications: [self::remoteApplication()]),
            $state,
        );

        self::assertSame(PlanOperation::UNSUPPORTED, self::action($plan, 'application.my-api')->operation);
        self::assertStringContainsString('conflicting state address', self::action($plan, 'application.my-api')->reason);
    }

    public function testManagedEnvironmentMissingOrReplacedNeverBecomesCreate(): void
    {
        $cloud = new PlanningCloudClient(
            applications: [self::remoteApplication()],
            environments: [new CloudEnvironment('env-replacement', 'app-1', 'production', 'develop')],
        );
        $plan = self::planner()->create(self::blueprint(), $cloud, self::managedState());
        $environment = self::action($plan, 'environment.production');

        self::assertSame(PlanOperation::UNSUPPORTED, $environment->operation);
        self::assertStringContainsString('same-name unmanaged replacement', $environment->reason);
        self::assertSame(0, $plan->countByOperation(PlanOperation::UPDATE));
    }

    public function testManagedEnvironmentMissingWithoutReplacementIsNonActionable(): void
    {
        $plan = self::planner()->create(
            self::blueprint(),
            new PlanningCloudClient(applications: [self::remoteApplication()]),
            self::managedState(),
        );

        self::assertSame(PlanOperation::UNSUPPORTED, self::action($plan, 'environment.production')->operation);
        self::assertStringContainsString('remote identity is missing', self::action($plan, 'environment.production')->reason);
    }

    public function testManagedEnvironmentWrongStateParentIsNonActionable(): void
    {
        $application = new ResourceAddress(ResourceType::APPLICATION, 'my-api');
        $state = StateDocument::empty()->withOrganization('acme')
            ->withResource(new StateResource($application, ResourceType::APPLICATION, 'app-1'))
            ->withResource(new StateResource(
                new ResourceAddress(ResourceType::ENVIRONMENT, 'production'),
                ResourceType::ENVIRONMENT,
                'env-prod',
                new ResourceAddress(ResourceType::APPLICATION, 'other'),
            ));
        $plan = self::planner()->create(
            self::blueprint(),
            new PlanningCloudClient(
                applications: [self::remoteApplication()],
                environments: [new CloudEnvironment('env-prod', 'app-1', 'production', 'main')],
            ),
            $state,
        );

        self::assertSame(PlanOperation::UNSUPPORTED, self::action($plan, 'environment.production')->operation);
        self::assertStringContainsString('parent relationship', self::action($plan, 'environment.production')->reason);
    }

    public function testManagedEnvironmentUnexpectedRemoteApplicationIsNonActionable(): void
    {
        $plan = self::planner()->create(
            self::blueprint(),
            new PlanningCloudClient(
                applications: [self::remoteApplication()],
                environments: [new CloudEnvironment('env-prod', 'app-other', 'production', 'main')],
            ),
            self::managedState(),
        );

        self::assertSame(PlanOperation::UNSUPPORTED, self::action($plan, 'environment.production')->operation);
        self::assertStringContainsString('unexpected remote application', self::action($plan, 'environment.production')->reason);
    }

    public function testUnmanagedMatchingEnvironmentDifferenceRequiresImport(): void
    {
        $plan = self::planner()->create(
            self::blueprint(),
            new PlanningCloudClient(
                applications: [self::remoteApplication()],
                environments: [
                    new CloudEnvironment('env-prod', 'app-1', 'production', 'different'),
                    new CloudEnvironment('env-stage', 'app-1', 'staging', 'develop'),
                ],
            ),
            StateDocument::empty(),
        );

        self::assertSame(PlanOperation::UNSUPPORTED, self::action($plan, 'environment.production')->operation);
        self::assertStringContainsString('Import it before', self::action($plan, 'environment.production')->reason);
        self::assertSame(0, $plan->countByOperation(PlanOperation::UPDATE));
    }

    public function testUnmanagedMatchingResourcesRemainReadOnlyAndUnadopted(): void
    {
        $state = StateDocument::empty();
        $plan = self::planner()->create(
            self::blueprint(),
            new PlanningCloudClient(
                applications: [self::remoteApplication()],
                environments: [
                    new CloudEnvironment('env-prod', 'app-1', 'production', 'main'),
                    new CloudEnvironment('env-stage', 'app-1', 'staging', 'develop'),
                ],
            ),
            $state,
        );

        self::assertSame(3, $plan->countByOperation(PlanOperation::NO_CHANGE));
        self::assertStringContainsString('unmanaged', self::action($plan, 'application.my-api')->reason);
        self::assertStringContainsString('unmanaged', self::action($plan, 'environment.production')->reason);
        self::assertSame([], $state->resources());
    }

    public function testOwnedOnlyApplicationIsVisibleWithoutDeletionSemantics(): void
    {
        $oldApplication = new ResourceAddress(ResourceType::APPLICATION, 'old-api');
        $state = StateDocument::empty()->withOrganization('acme')->withResource(
            new StateResource($oldApplication, ResourceType::APPLICATION, 'app-old'),
        );
        $plan = self::planner()->create(
            self::blueprint(),
            new PlanningCloudClient(applications: [
                self::remoteApplication(),
                new CloudApplication('app-old', 'old-api', 'old-api', 'eu-central-1', 'acme/old-api'),
            ]),
            $state,
        );

        $action = self::action($plan, 'application.old-api');
        self::assertSame(PlanOperation::UNSUPPORTED, $action->operation);
        self::assertSame(
            'This Application is owned by LCB but is absent from the blueprint. Automatic removal is not supported.',
            $action->reason,
        );
        self::assertNull($action->remoteId);
        self::assertSame(1, count($state->resources()));
    }

    public function testOwnedOnlyApplicationMissingIdentityDiagnosesReplacementWithoutAdoption(): void
    {
        $oldApplication = new ResourceAddress(ResourceType::APPLICATION, 'old-api');
        $state = StateDocument::empty()->withOrganization('acme')->withResource(
            new StateResource($oldApplication, ResourceType::APPLICATION, 'app-missing'),
        );
        $plan = self::planner()->create(
            self::blueprint(),
            new PlanningCloudClient(applications: [
                self::remoteApplication(),
                new CloudApplication('app-replacement', 'old-api', 'old-api', 'eu-central-1', 'acme/old-api'),
            ]),
            $state,
        );

        $action = self::action($plan, 'application.old-api');
        self::assertSame(PlanOperation::UNSUPPORTED, $action->operation);
        self::assertStringContainsString('recorded remote identity is missing', $action->reason);
        self::assertStringContainsString('same-name unmanaged replacement', $action->reason);
        self::assertNull($action->remoteId);
    }

    public function testOwnedOnlyMissingApplicationRetainsStateAndNeverBecomesCreate(): void
    {
        $oldApplication = new ResourceAddress(ResourceType::APPLICATION, 'old-api');
        $state = StateDocument::empty()->withOrganization('acme')->withResource(
            new StateResource($oldApplication, ResourceType::APPLICATION, 'app-missing'),
        );
        $plan = self::planner()->create(
            self::blueprint(),
            new PlanningCloudClient(applications: [self::remoteApplication()]),
            $state,
        );

        $action = self::action($plan, 'application.old-api');
        self::assertSame(PlanOperation::UNSUPPORTED, $action->operation);
        self::assertStringContainsString('recorded remote identity is missing', $action->reason);
        self::assertSame('app-missing', $state->get($oldApplication)->remoteId);
    }

    public function testOwnedOnlyEnvironmentUnderDesiredOwnedParentIsVisible(): void
    {
        $application = new ResourceAddress(ResourceType::APPLICATION, 'my-api');
        $preview = new ResourceAddress(ResourceType::ENVIRONMENT, 'preview');
        $state = self::managedState()->withResource(
            new StateResource($preview, ResourceType::ENVIRONMENT, 'env-preview', $application),
        );
        $plan = self::planner()->create(
            self::blueprint(),
            new PlanningCloudClient(
                applications: [self::remoteApplication()],
                environments: [
                    new CloudEnvironment('env-prod', 'app-1', 'production', 'main'),
                    new CloudEnvironment('env-preview', 'app-1', 'preview', 'feature'),
                ],
            ),
            $state,
        );

        $action = self::action($plan, 'environment.preview');
        self::assertSame(PlanOperation::UNSUPPORTED, $action->operation);
        self::assertStringContainsString('owned by LCB', $action->reason);
        self::assertStringContainsString('absent from the blueprint', $action->reason);
        self::assertNull($action->remoteId);
    }

    public function testOwnedOnlyEnvironmentMissingIdentityDiagnosesSameNameReplacement(): void
    {
        $application = new ResourceAddress(ResourceType::APPLICATION, 'my-api');
        $state = self::managedState()->withResource(new StateResource(
            new ResourceAddress(ResourceType::ENVIRONMENT, 'preview'),
            ResourceType::ENVIRONMENT,
            'env-missing',
            $application,
        ));
        $plan = self::planner()->create(
            self::blueprint(),
            new PlanningCloudClient(
                applications: [self::remoteApplication()],
                environments: [
                    new CloudEnvironment('env-prod', 'app-1', 'production', 'main'),
                    new CloudEnvironment('env-replacement', 'app-1', 'preview', 'feature'),
                ],
            ),
            $state,
        );

        $action = self::action($plan, 'environment.preview');
        self::assertSame(PlanOperation::UNSUPPORTED, $action->operation);
        self::assertStringContainsString('recorded remote identity is missing', $action->reason);
        self::assertStringContainsString('same-name unmanaged replacement', $action->reason);
    }

    public function testOwnedOnlyEnvironmentMissingIdentityRemainsVisible(): void
    {
        $application = new ResourceAddress(ResourceType::APPLICATION, 'my-api');
        $state = self::managedState()->withResource(new StateResource(
            new ResourceAddress(ResourceType::ENVIRONMENT, 'preview'),
            ResourceType::ENVIRONMENT,
            'env-missing',
            $application,
        ));
        $plan = self::planner()->create(
            self::blueprint(),
            new PlanningCloudClient(
                applications: [self::remoteApplication()],
                environments: [new CloudEnvironment('env-prod', 'app-1', 'production', 'main')],
            ),
            $state,
        );

        $action = self::action($plan, 'environment.preview');
        self::assertSame(PlanOperation::UNSUPPORTED, $action->operation);
        self::assertStringContainsString('recorded remote identity is missing', $action->reason);
        self::assertSame('env-missing', $state->get($action->address)->remoteId);
    }

    public function testOwnedOnlyEnvironmentRemainsVisibleWhenDesiredParentIdentityIsMissing(): void
    {
        $application = new ResourceAddress(ResourceType::APPLICATION, 'my-api');
        $state = self::managedState()->withResource(new StateResource(
            new ResourceAddress(ResourceType::ENVIRONMENT, 'preview'),
            ResourceType::ENVIRONMENT,
            'env-preview',
            $application,
        ));
        $plan = self::planner()->create(self::blueprint(), new PlanningCloudClient(), $state);

        self::assertSame(
            PlanOperation::UNSUPPORTED,
            self::action($plan, 'application.my-api')->operation,
        );
        $environment = self::action($plan, 'environment.preview');
        self::assertSame(PlanOperation::UNSUPPORTED, $environment->operation);
        self::assertStringContainsString('parent Application identity is missing', $environment->reason);
    }

    public function testOwnedOnlyEnvironmentWithMissingParentIsVisibleAsStructurallyInvalid(): void
    {
        $state = StateDocument::empty()->withOrganization('acme')->withResource(new StateResource(
            new ResourceAddress(ResourceType::ENVIRONMENT, 'preview'),
            ResourceType::ENVIRONMENT,
            'env-preview',
            new ResourceAddress(ResourceType::APPLICATION, 'missing'),
        ));
        $plan = self::planner()->create(
            self::blueprint(),
            new PlanningCloudClient(applications: [self::remoteApplication()]),
            $state,
        );

        $action = self::action($plan, 'environment.preview');
        self::assertSame(PlanOperation::UNSUPPORTED, $action->operation);
        self::assertSame(
            'Owned Environment state has an unresolvable parent ownership relationship and is absent from the blueprint.',
            $action->reason,
        );
    }

    public function testRemovedApplicationSubtreeIsParentFirstAndEnvironmentsAreSorted(): void
    {
        $oldApplication = new ResourceAddress(ResourceType::APPLICATION, 'old-api');
        $state = StateDocument::empty()->withOrganization('acme')
            ->withResource(new StateResource(
                new ResourceAddress(ResourceType::ENVIRONMENT, 'zeta'),
                ResourceType::ENVIRONMENT,
                'env-zeta',
                $oldApplication,
            ))
            ->withResource(new StateResource($oldApplication, ResourceType::APPLICATION, 'app-old'))
            ->withResource(new StateResource(
                new ResourceAddress(ResourceType::ENVIRONMENT, 'alpha'),
                ResourceType::ENVIRONMENT,
                'env-alpha',
                $oldApplication,
            ));
        $plan = self::planner()->create(
            self::blueprint(),
            new PlanningCloudClient(
                applications: [
                    self::remoteApplication(),
                    new CloudApplication('app-old', 'old-api', 'old-api', 'eu-central-1', 'acme/old-api'),
                ],
                environmentsByApplication: [
                    'app-old' => [
                        new CloudEnvironment('env-zeta', 'app-old', 'zeta', 'zeta'),
                        new CloudEnvironment('env-alpha', 'app-old', 'alpha', 'alpha'),
                    ],
                ],
            ),
            $state,
        );

        self::assertSame([
            'application.my-api',
            'application.old-api',
            'environment.production',
            'environment.staging',
            'environment.alpha',
            'environment.zeta',
        ], self::addresses($plan));
        self::assertSame(3, $plan->countByOperation(PlanOperation::UNSUPPORTED));
    }

    public function testAddressChangeDoesNotInferRename(): void
    {
        $application = new ResourceAddress(ResourceType::APPLICATION, 'my-api');
        $state = StateDocument::empty()->withOrganization('acme')
            ->withResource(new StateResource($application, ResourceType::APPLICATION, 'app-1'))
            ->withResource(new StateResource(
                new ResourceAddress(ResourceType::ENVIRONMENT, 'production'),
                ResourceType::ENVIRONMENT,
                'env-production',
                $application,
            ));
        $blueprint = new Blueprint(
            BlueprintSchemaVersion::V1,
            'acme',
            self::blueprint()->application,
            new EnvironmentDefinitionCollection(
                new EnvironmentDefinition('prod', 'main', new VariableDefinitionCollection()),
            ),
        );
        $plan = self::planner()->create(
            $blueprint,
            new PlanningCloudClient(
                applications: [self::remoteApplication()],
                environments: [new CloudEnvironment('env-production', 'app-1', 'production', 'main')],
            ),
            $state,
        );

        self::assertSame(PlanOperation::CREATE, self::action($plan, 'environment.prod')->operation);
        self::assertSame(PlanOperation::UNSUPPORTED, self::action($plan, 'environment.production')->operation);
    }

    public function testOwnedOnlyEnvironmentFoundUnderUnexpectedApplicationIsUnsupported(): void
    {
        $application = new ResourceAddress(ResourceType::APPLICATION, 'my-api');
        $state = self::managedState()->withResource(new StateResource(
            new ResourceAddress(ResourceType::ENVIRONMENT, 'preview'),
            ResourceType::ENVIRONMENT,
            'env-preview',
            $application,
        ));
        $plan = self::planner()->create(
            self::blueprint(),
            new PlanningCloudClient(
                applications: [
                    self::remoteApplication(),
                    new CloudApplication('app-other', 'other', 'other', 'eu-central-1', 'acme/other'),
                ],
                environmentsByApplication: [
                    'app-1' => [new CloudEnvironment('env-prod', 'app-1', 'production', 'main')],
                    'app-other' => [new CloudEnvironment('env-preview', 'app-other', 'preview', 'feature')],
                ],
            ),
            $state,
        );

        $action = self::action($plan, 'environment.preview');
        self::assertSame(PlanOperation::UNSUPPORTED, $action->operation);
        self::assertStringContainsString('unexpected Application', $action->reason);
    }

    private function planWithApplication(CloudApplication $application): ExecutionPlan
    {
        return self::planner()->create(
            self::blueprint(),
            new PlanningCloudClient(applications: [$application]),
            StateDocument::empty(),
        );
    }

    private static function planner(): CreatePlan
    {
        return new CreatePlan(new VariableValueResolver(new PlanningEnvironmentValueProvider()));
    }

    private static function managedState(): StateDocument
    {
        $application = new ResourceAddress(ResourceType::APPLICATION, 'my-api');

        return StateDocument::empty()->withOrganization('acme')
            ->withResource(new StateResource($application, ResourceType::APPLICATION, 'app-1'))
            ->withResource(new StateResource(
                new ResourceAddress(ResourceType::ENVIRONMENT, 'production'),
                ResourceType::ENVIRONMENT,
                'env-prod',
                $application,
            ));
    }

    private static function blueprint(): Blueprint
    {
        return new Blueprint(
            BlueprintSchemaVersion::V1,
            'acme',
            new ApplicationDefinition(
                'my-api',
                'eu-central-1',
                new SourceDefinition(SourceProvider::GITHUB, 'acme/my-api'),
            ),
            new EnvironmentDefinitionCollection(
                new EnvironmentDefinition('production', 'main', new VariableDefinitionCollection()),
                new EnvironmentDefinition('staging', 'develop', new VariableDefinitionCollection()),
            ),
        );
    }

    private static function remoteApplication(
        string $id = 'app-1',
        string $region = 'eu-central-1',
        ?string $repository = 'acme/my-api',
    ): CloudApplication {
        return new CloudApplication($id, 'my-api', 'my-api', $region, $repository);
    }

    /** @return list<PlanAction> */
    private static function actions(ExecutionPlan $plan): array
    {
        return iterator_to_array($plan, false);
    }

    private static function action(ExecutionPlan $plan, string $address): PlanAction
    {
        foreach ($plan as $action) {
            if ((string) $action->address === $address) {
                return $action;
            }
        }

        throw new LogicException(sprintf('Missing plan action "%s".', $address));
    }

    /** @return list<string> */
    private static function addresses(ExecutionPlan $plan): array
    {
        return array_map(
            static fn (PlanAction $action): string => (string) $action->address,
            self::actions($plan),
        );
    }
}

final class PlanningCloudClient implements LaravelCloudClient
{
    public function updateEnvironment(string $environmentId, \LaravelCloudBlueprint\Cloud\DTO\UpdateEnvironmentRequest $request): \LaravelCloudBlueprint\Cloud\DTO\UpdatedCloudEnvironment
    {
        throw new LogicException('Planner fake must remain read-only.');
    }
    /** @var list<string> */
    public array $calls = [];

    /**
     * @param list<CloudApplication> $applications
     * @param list<CloudEnvironment> $environments
     */
    public function __construct(
        private string $organizationSlug = 'acme',
        private array $applications = [],
        private array $environments = [],
        /** @var array<string, list<CloudEnvironment>> */
        private array $environmentsByApplication = [],
    ) {
    }

    public function organization(): CloudOrganization
    {
        $this->calls[] = 'organization';
        return new CloudOrganization('org-1', 'Acme', $this->organizationSlug);
    }

    public function applications(): array
    {
        $this->calls[] = 'applications';
        return $this->applications;
    }

    public function environments(string $applicationId): array
    {
        $this->calls[] = 'environments:' . $applicationId;
        return $this->environmentsByApplication[$applicationId] ?? $this->environments;
    }

    public function environment(string $environmentId): CloudEnvironmentDetails
    {
        $this->calls[] = 'environment:' . $environmentId;
        return new CloudEnvironmentDetails($environmentId, 'production', null);
    }

    public function createApplication(CreateApplicationRequest $request): CloudApplication
    {
        throw new LogicException('Planner fake must remain read-only.');
    }

    public function createEnvironment(string $applicationId, CreateEnvironmentRequest $request): CloudEnvironment
    {
        throw new LogicException('Planner fake must remain read-only.');
    }

    public function setEnvironmentVariables(string $environmentId, SetEnvironmentVariablesRequest $request): void
    {
        throw new LogicException('Planner fake must remain read-only.');
    }
}

final readonly class PlanningEnvironmentValueProvider implements EnvironmentValueProvider
{
    public function value(string $name): ?string
    {
        return null;
    }
}
