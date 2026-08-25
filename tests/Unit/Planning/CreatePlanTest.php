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
use LaravelCloudBlueprint\Planning\VariableValueResolver;
use PHPUnit\Framework\TestCase;

final class CreatePlanTest extends TestCase
{
    public function testOrganizationMustMatchByExactSlug(): void
    {
        $cloud = new PlanningCloudClient('wrong-organization');

        $this->expectException(OrganizationMismatchException::class);

        self::planner()->create(self::blueprint(), $cloud);
    }

    public function testMissingApplicationCreatesApplicationAndEveryEnvironmentWithoutEnvironmentLookup(): void
    {
        $cloud = new PlanningCloudClient();

        $plan = self::planner()->create(self::blueprint(), $cloud);

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

        $plan = self::planner()->create(self::blueprint(), $cloud);

        self::assertSame(3, $plan->countByOperation(PlanOperation::NO_CHANGE));
        self::assertSame(0, $plan->countByOperation(PlanOperation::CREATE));
        self::assertFalse($plan->hasActionableChanges());
        self::assertSame(['organization', 'applications', 'environments:app-1'], $cloud->calls);
    }

    public function testApplicationRegionDifferenceIsUnsupported(): void
    {
        $plan = $this->planWithApplication(self::remoteApplication(region: 'us-east-1'));

        self::assertSame(PlanOperation::UNSUPPORTED, self::actions($plan)[0]->operation);
        self::assertStringContainsString('region differs', self::actions($plan)[0]->reason);
    }

    public function testApplicationRepositoryDifferenceIsUpdateWithSafeChange(): void
    {
        $plan = $this->planWithApplication(self::remoteApplication(repository: 'other/repository'));

        $action = self::actions($plan)[0];
        self::assertSame(PlanOperation::UPDATE, $action->operation);
        self::assertSame('repository', $action->changes[0]->field);
        self::assertSame('other/repository', $action->changes[0]->before);
        self::assertSame('acme/my-api', $action->changes[0]->after);
        self::assertTrue($plan->hasActionableChanges());
        self::assertSame(1, $plan->countByOperation(PlanOperation::UPDATE));
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

        self::planner()->create(self::blueprint(), $cloud);
    }

    public function testEnvironmentMatchingProducesCreateNoChangeAndUpdateInBlueprintOrder(): void
    {
        $cloud = new PlanningCloudClient(
            applications: [self::remoteApplication()],
            environments: [new CloudEnvironment('env-prod', 'app-1', 'production', 'other')],
        );

        $actions = self::actions(self::planner()->create(self::blueprint(), $cloud));

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
            self::actions(self::planner()->create(self::blueprint(), $cloud))[1]->operation,
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

        self::planner()->create(self::blueprint(), $cloud);
    }

    private function planWithApplication(CloudApplication $application): ExecutionPlan
    {
        return self::planner()->create(
            self::blueprint(),
            new PlanningCloudClient(applications: [$application]),
        );
    }

    private static function planner(): CreatePlan
    {
        return new CreatePlan(new VariableValueResolver(new PlanningEnvironmentValueProvider()));
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
    public function updateApplication(string $applicationId, \LaravelCloudBlueprint\Cloud\DTO\UpdateApplicationRequest $request): CloudApplication
    {
        throw new LogicException('Planner fake must remain read-only.');
    }
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
        return $this->environments;
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
