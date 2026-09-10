<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Planning;

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
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironmentVariable;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironmentVariableCollection;
use LaravelCloudBlueprint\Cloud\DTO\CloudOrganization;
use LaravelCloudBlueprint\Cloud\DTO\CreateApplicationRequest;
use LaravelCloudBlueprint\Cloud\DTO\CreateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\DTO\SetEnvironmentVariablesRequest;
use LaravelCloudBlueprint\Planning\Contract\EnvironmentValueProvider;
use LaravelCloudBlueprint\Planning\CreatePlan;
use LaravelCloudBlueprint\Planning\Exception\MissingEnvironmentValueException;
use LaravelCloudBlueprint\Planning\ExecutionPlan;
use LaravelCloudBlueprint\Planning\PlanAction;
use LaravelCloudBlueprint\Planning\PlanOperation;
use LaravelCloudBlueprint\Planning\PlanReconciliationStatus;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\Planning\VariableValueResolver;
use LaravelCloudBlueprint\State\StateDocument;
use LaravelCloudBlueprint\State\StateResource;
use LogicException;
use PHPUnit\Framework\TestCase;

final class VariablePlanningTest extends TestCase
{
    public function testLiteralAndEnvironmentReferenceValuesResolveThroughTheAbstraction(): void
    {
        $resolver = new VariableValueResolver(new VariablePlanningEnvironment(['LOCAL_KEY' => 'resolved-secret']));
        $address = new ResourceAddress(ResourceType::VARIABLE, 'production.APP_KEY');

        self::assertSame('literal-value', $resolver->resolve(
            new VariableDefinition('APP_ENV', new LiteralVariableValue('literal-value'), false),
            $address,
        ));
        self::assertSame('resolved-secret', $resolver->resolve(
            new VariableDefinition('APP_KEY', new EnvironmentVariableReference('LOCAL_KEY'), true),
            $address,
        ));
    }

    public function testMissingEnvironmentReferenceFailsWithoutExposingReferenceOrValue(): void
    {
        $resolver = new VariableValueResolver(new VariablePlanningEnvironment());

        try {
            $resolver->resolve(
                new VariableDefinition('APP_KEY', new EnvironmentVariableReference('LOCAL_DATABASE_PASSWORD'), true),
                new ResourceAddress(ResourceType::VARIABLE, 'production.APP_KEY'),
            );
            self::fail('Expected missing environment value failure.');
        } catch (MissingEnvironmentValueException $exception) {
            self::assertStringContainsString('variable.production.APP_KEY', $exception->getMessage());
            self::assertStringNotContainsString('LOCAL_DATABASE_PASSWORD', $exception->getMessage());
        }
    }

    public function testExistingVariablesPlanCreateNoChangeAndUpdateWhileIgnoringExtras(): void
    {
        $cloud = VariablePlanningCloud::existing(new CloudEnvironmentVariableCollection(
            new CloudEnvironmentVariable('EQUAL', 'same'),
            new CloudEnvironmentVariable('DIFFERENT', 'remote-secret'),
            new CloudEnvironmentVariable('EXTRA', 'ignored-secret'),
        ));

        $actions = self::actions($this->planner()->create(self::blueprint(
            new VariableDefinition('MISSING', new LiteralVariableValue('new-secret'), true),
            new VariableDefinition('EQUAL', new LiteralVariableValue('same'), false),
            new VariableDefinition('DIFFERENT', new LiteralVariableValue('desired-secret'), true),
        ), $cloud, StateDocument::empty()));

        self::assertSame(
            ['application.my-api', 'environment.production', 'variable.production.MISSING', 'variable.production.EQUAL', 'variable.production.DIFFERENT'],
            array_map(static fn (PlanAction $action): string => (string) $action->address, $actions),
        );
        self::assertSame(PlanOperation::CREATE, $actions[2]->operation);
        self::assertSame(PlanOperation::NO_CHANGE, $actions[3]->operation);
        self::assertSame(PlanOperation::UPDATE, $actions[4]->operation);
        self::assertSame(PlanReconciliationStatus::SUPPORTED, $actions[2]->reconciliation);
        self::assertSame(PlanReconciliationStatus::NOT_APPLICABLE, $actions[3]->reconciliation);
        self::assertSame(PlanReconciliationStatus::SUPPORTED, $actions[4]->reconciliation);
        self::assertSame('Environment variable differs from desired state.', $actions[4]->reason);
        self::assertSame([], $actions[4]->changes);
        self::assertSame(['organization', 'applications', 'environments:app-1', 'environment:env-1'], $cloud->calls);

        $serialized = serialize($actions);
        foreach (['new-secret', 'same', 'remote-secret', 'ignored-secret', 'desired-secret'] as $secret) {
            self::assertStringNotContainsString($secret, $serialized);
        }
    }

    public function testUnavailableRemoteVariableInformationProducesUnsupportedWithoutGuessing(): void
    {
        $actions = self::actions($this->planner()->create(
            self::blueprint(new VariableDefinition('APP_ENV', new LiteralVariableValue('production'), false)),
            VariablePlanningCloud::existing(null),
            StateDocument::empty(),
        ));

        self::assertSame(PlanOperation::UNSUPPORTED, $actions[2]->operation);
        self::assertSame(PlanReconciliationStatus::UNSUPPORTED, $actions[2]->reconciliation);
        self::assertSame('Remote environment variable information is unavailable.', $actions[2]->reason);
    }

    public function testEnvironmentCreatePlansVariablesWithoutDetailsLookup(): void
    {
        $cloud = VariablePlanningCloud::existing(new CloudEnvironmentVariableCollection(), environmentExists: false);
        $plan = $this->planner()->create(
            self::blueprint(new VariableDefinition('APP_ENV', new LiteralVariableValue('production'), false)),
            $cloud,
            self::managedApplicationState(),
        );

        self::assertSame(
            ['application.my-api', 'environment.production', 'variable.production.APP_ENV'],
            self::addresses($plan),
        );
        self::assertSame(PlanOperation::CREATE, self::actions($plan)[2]->operation);
        self::assertSame(['organization', 'applications', 'environments:app-1'], $cloud->calls);
    }

    public function testApplicationCreatePlansEnvironmentsThenVariablesWithoutRemoteEnvironmentCalls(): void
    {
        $cloud = VariablePlanningCloud::missingApplication();
        $plan = $this->planner()->create(
            self::blueprint(new VariableDefinition('APP_KEY', new EnvironmentVariableReference('LOCAL_KEY'), true)),
            $cloud,
            StateDocument::empty(),
        );

        self::assertSame(
            ['application.my-api', 'environment.production', 'variable.production.APP_KEY'],
            self::addresses($plan),
        );
        self::assertSame(3, $plan->countByOperation(PlanOperation::CREATE));
        self::assertSame(['organization', 'applications'], $cloud->calls);
        self::assertSame(0, $cloud->postCount);
    }

    public function testVariableAddressRoundTripsStably(): void
    {
        $address = ResourceAddress::fromString('variable.production.APP_KEY');

        self::assertSame(ResourceType::VARIABLE, $address->type);
        self::assertSame('production.APP_KEY', $address->name);
        self::assertSame('variable.production.APP_KEY', (string) $address);
    }

    private function planner(): CreatePlan
    {
        return new CreatePlan(new VariableValueResolver(new VariablePlanningEnvironment([
            'LOCAL_KEY' => 'local-sensitive-value',
        ])));
    }

    private static function managedApplicationState(): StateDocument
    {
        $address = new ResourceAddress(ResourceType::APPLICATION, 'my-api');

        return StateDocument::empty()->withOrganization('acme')->withResource(
            new StateResource($address, ResourceType::APPLICATION, 'app-1'),
        );
    }

    private static function blueprint(VariableDefinition ...$variables): Blueprint
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
                new EnvironmentDefinition('production', 'main', new VariableDefinitionCollection(...$variables)),
            ),
        );
    }

    /** @return list<PlanAction> */
    private static function actions(ExecutionPlan $plan): array
    {
        return iterator_to_array($plan, false);
    }

    /** @return list<string> */
    private static function addresses(ExecutionPlan $plan): array
    {
        return array_map(static fn (PlanAction $action): string => (string) $action->address, self::actions($plan));
    }
}

final readonly class VariablePlanningEnvironment implements EnvironmentValueProvider
{
    /** @param array<string, string> $values */
    public function __construct(private array $values = [])
    {
    }

    public function value(string $name): ?string
    {
        return $this->values[$name] ?? null;
    }
}

final class VariablePlanningCloud implements LaravelCloudClient
{
    public function updateEnvironment(string $environmentId, \LaravelCloudBlueprint\Cloud\DTO\UpdateEnvironmentRequest $request): \LaravelCloudBlueprint\Cloud\DTO\UpdatedCloudEnvironment
    {
        throw new LogicException('Planner fake must remain read-only.');
    }
    /** @var list<string> */
    public array $calls = [];
    public int $postCount = 0;

    private function __construct(
        private readonly bool $applicationExists,
        private readonly bool $environmentExists,
        private readonly ?CloudEnvironmentVariableCollection $variables,
    ) {
    }

    public static function existing(
        ?CloudEnvironmentVariableCollection $variables,
        bool $environmentExists = true,
    ): self {
        return new self(true, $environmentExists, $variables);
    }

    public static function missingApplication(): self
    {
        return new self(false, false, null);
    }

    public function organization(): CloudOrganization
    {
        $this->calls[] = 'organization';
        return new CloudOrganization('org-1', 'Acme', 'acme');
    }

    public function applications(): array
    {
        $this->calls[] = 'applications';
        return $this->applicationExists
            ? [new CloudApplication('app-1', 'my-api', 'my-api', 'eu-central-1', 'acme/my-api')]
            : [];
    }

    public function environments(string $applicationId): array
    {
        $this->calls[] = 'environments:' . $applicationId;
        return $this->environmentExists
            ? [new CloudEnvironment('env-1', $applicationId, 'production', 'main')]
            : [];
    }

    public function environment(string $environmentId): CloudEnvironmentDetails
    {
        $this->calls[] = 'environment:' . $environmentId;
        return new CloudEnvironmentDetails($environmentId, 'production', $this->variables);
    }

    public function createApplication(CreateApplicationRequest $request): CloudApplication
    {
        ++$this->postCount;
        throw new LogicException('Variable planning must remain read-only.');
    }

    public function createEnvironment(string $applicationId, CreateEnvironmentRequest $request): CloudEnvironment
    {
        ++$this->postCount;
        throw new LogicException('Variable planning must remain read-only.');
    }

    public function setEnvironmentVariables(string $environmentId, SetEnvironmentVariablesRequest $request): void
    {
        ++$this->postCount;
        throw new LogicException('Variable planning must remain read-only.');
    }
}
