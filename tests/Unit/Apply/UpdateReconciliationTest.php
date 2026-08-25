<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Apply;

use LaravelCloudBlueprint\Apply\ApplyStatus;
use LaravelCloudBlueprint\Apply\CreateOnlyApply;
use LaravelCloudBlueprint\Blueprint\ApplicationDefinition;
use LaravelCloudBlueprint\Blueprint\Blueprint;
use LaravelCloudBlueprint\Blueprint\BlueprintSchemaVersion;
use LaravelCloudBlueprint\Blueprint\EnvironmentDefinition;
use LaravelCloudBlueprint\Blueprint\EnvironmentDefinitionCollection;
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
use LaravelCloudBlueprint\Cloud\DTO\UpdateApplicationRequest;
use LaravelCloudBlueprint\Cloud\DTO\UpdatedCloudEnvironment;
use LaravelCloudBlueprint\Cloud\DTO\UpdateEnvironmentRequest;
use LaravelCloudBlueprint\Planning\Contract\EnvironmentValueProvider;
use LaravelCloudBlueprint\Planning\CreatePlan;
use LaravelCloudBlueprint\Planning\PlanOperation;
use LaravelCloudBlueprint\Planning\VariableValueResolver;
use LaravelCloudBlueprint\State\Contract\StateStore;
use LaravelCloudBlueprint\State\Contract\StateTransaction;
use LaravelCloudBlueprint\State\StateDocument;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UpdateReconciliationTest extends TestCase
{
    /** @return iterable<string, array{bool, bool, bool, int}> */
    public static function updateScenarioProvider(): iterable
    {
        yield 'repository' => [true, false, false, 1];
        yield 'branch' => [false, true, false, 1];
        yield 'variable' => [false, false, true, 1];
        yield 'all updates' => [true, true, true, 3];
    }

    #[DataProvider('updateScenarioProvider')]
    public function testSuccessfulUpdatesConvergeThroughTheRealPlannerToNoChange(
        bool $repositoryDiffers,
        bool $branchDiffers,
        bool $variableDiffers,
        int $expectedUpdates,
    ): void {
        $blueprint = self::blueprint();
        $cloud = new ReconciliationCloud(
            $repositoryDiffers ? 'acme/old-api' : 'acme/my-api',
            $branchDiffers ? 'main' : 'develop',
            $variableDiffers ? 'remote-value' : 'desired-sensitive-value',
        );
        $values = new VariableValueResolver(new ReconciliationEnvironmentValues());
        $planner = new CreatePlan($values);
        $state = new ReconciliationState(StateDocument::empty()->withOrganization('acme')->withSerial(11));

        $before = $planner->create($blueprint, $cloud);
        self::assertSame($expectedUpdates, $before->countByOperation(PlanOperation::UPDATE));

        $result = (new CreateOnlyApply($values))->execute($blueprint, $before, $cloud, $state);

        self::assertSame(ApplyStatus::SUCCESS, $result->status);
        self::assertSame($expectedUpdates, $result->updatedCount());
        self::assertSame(11, $state->state->serial);
        self::assertSame(0, $state->saveCount);

        $after = $planner->create($blueprint, $cloud);
        self::assertSame(0, $after->countByOperation(PlanOperation::UPDATE));
        self::assertSame(3, $after->countByOperation(PlanOperation::NO_CHANGE));

        $safeArtifacts = serialize([$before, $result, $after, $state->state]);
        self::assertStringNotContainsString('desired-sensitive-value', $safeArtifacts);
        self::assertStringNotContainsString('remote-value', $safeArtifacts);
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
            new EnvironmentDefinitionCollection(new EnvironmentDefinition(
                'production',
                'develop',
                new VariableDefinitionCollection(new VariableDefinition(
                    'APP_ENV',
                    new LiteralVariableValue('desired-sensitive-value'),
                    true,
                )),
            )),
        );
    }
}

final readonly class ReconciliationEnvironmentValues implements EnvironmentValueProvider
{
    public function value(string $name): ?string
    {
        return null;
    }
}

final class ReconciliationCloud implements LaravelCloudClient
{
    public function __construct(
        private string $repository,
        private string $branch,
        private string $variableValue,
    ) {
    }

    public function organization(): CloudOrganization
    {
        return new CloudOrganization('org-1', 'Acme', 'acme');
    }

    public function applications(): array
    {
        return [new CloudApplication('app-1', 'my-api', 'my-api', 'eu-central-1', $this->repository)];
    }

    public function environments(string $applicationId): array
    {
        return [new CloudEnvironment('env-1', $applicationId, 'production', $this->branch)];
    }

    public function environment(string $environmentId): CloudEnvironmentDetails
    {
        return new CloudEnvironmentDetails($environmentId, 'production', new CloudEnvironmentVariableCollection(
            new CloudEnvironmentVariable('APP_ENV', $this->variableValue),
        ));
    }

    public function createApplication(CreateApplicationRequest $request): CloudApplication
    {
        throw new LogicException('Reconciliation fixture does not create resources.');
    }

    public function updateApplication(string $applicationId, UpdateApplicationRequest $request): CloudApplication
    {
        $this->repository = $request->repository;
        return new CloudApplication(
            $applicationId,
            'my-api',
            'my-api',
            'eu-central-1',
            $this->repository,
            $request->sourceProvider,
        );
    }

    public function createEnvironment(string $applicationId, CreateEnvironmentRequest $request): CloudEnvironment
    {
        throw new LogicException('Reconciliation fixture does not create resources.');
    }

    public function updateEnvironment(string $environmentId, UpdateEnvironmentRequest $request): UpdatedCloudEnvironment
    {
        $this->branch = $request->branch;
        return new UpdatedCloudEnvironment($environmentId, 'production', $this->branch);
    }

    public function setEnvironmentVariables(string $environmentId, SetEnvironmentVariablesRequest $request): void
    {
        foreach ($request->variables() as $variable) {
            if ($variable->key === 'APP_ENV') {
                $this->variableValue = $variable->value;
            }
        }
    }
}

final class ReconciliationState implements StateStore, StateTransaction
{
    public int $saveCount = 0;

    public function __construct(public StateDocument $state)
    {
    }

    public function begin(): StateTransaction
    {
        return $this;
    }

    public function load(): StateDocument
    {
        return $this->state;
    }

    public function save(StateDocument $state): StateDocument
    {
        ++$this->saveCount;
        $this->state = $state;
        return $state;
    }

    public function release(): void
    {
    }
}
