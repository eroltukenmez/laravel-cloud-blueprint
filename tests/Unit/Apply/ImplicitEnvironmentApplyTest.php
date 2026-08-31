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
use LaravelCloudBlueprint\Cloud\DTO\CloudOrganization;
use LaravelCloudBlueprint\Cloud\DTO\CreateApplicationRequest;
use LaravelCloudBlueprint\Cloud\DTO\CreateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\DTO\SetEnvironmentVariablesRequest;
use LaravelCloudBlueprint\Cloud\Exception\CloudApiException;
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

final class ImplicitEnvironmentApplyTest extends TestCase
{
    public function testFullFirstApplyReconcilesCheckpointsAndUsesImplicitEnvironmentForVariables(): void
    {
        $events = new ImplicitApplyEvents();
        $cloud = new ImplicitApplyCloud($events, [
            new CloudEnvironment('env-implicit', 'app-created', 'production', 'main'),
        ]);
        $state = new ImplicitApplyState($events);

        $result = self::apply()->execute(self::blueprint(), self::fullPlan(), $cloud, $state);

        self::assertSame(ApplyStatus::SUCCESS, $result->status);
        self::assertSame(3, $result->createdCount());
        self::assertSame(0, $cloud->environmentCreateCount);
        self::assertSame('env-implicit', $cloud->variableEnvironmentId);
        self::assertSame([
            'lock',
            'create:application',
            'save:application.lcb-e2e-test',
            'refresh:environments.app-created',
            'save:environment.production',
            'set:variables.env-implicit',
            'release',
        ], $events->values);
        self::assertSame('app-created', $state->state->get(self::address(ResourceType::APPLICATION, 'lcb-e2e-test'))->remoteId);
        self::assertSame('env-implicit', $state->state->get(self::address(ResourceType::ENVIRONMENT, 'production'))->remoteId);
        self::assertSame(2, $state->state->serial);
    }

    /** @return iterable<string, array{?string, string}> */
    public static function invalidImplicitBranchProvider(): iterable
    {
        yield 'mismatch' => ['develop', 'different branch'];
        yield 'unavailable' => [null, 'Branch information is unavailable'];
    }

    #[DataProvider('invalidImplicitBranchProvider')]
    public function testInvalidImplicitBranchStopsWithoutEnvironmentOrVariableMutation(
        ?string $branch,
        string $message,
    ): void {
        $events = new ImplicitApplyEvents();
        $cloud = new ImplicitApplyCloud($events, [
            new CloudEnvironment('env-implicit', 'app-created', 'production', $branch),
        ]);
        $state = new ImplicitApplyState($events);

        $result = self::apply()->execute(self::blueprint(), self::fullPlan(), $cloud, $state);

        self::assertSame(ApplyStatus::PARTIAL_FAILURE, $result->status);
        self::assertSame(0, $cloud->environmentCreateCount);
        self::assertNull($cloud->variableEnvironmentId);
        self::assertNotNull($state->state->find(self::address(ResourceType::APPLICATION, 'lcb-e2e-test')));
        self::assertNull($state->state->find(self::address(ResourceType::ENVIRONMENT, 'production')));
        self::assertStringContainsString($message, self::lastMessage($result));
    }

    public function testMissingImplicitEnvironmentFallsBackToEnvironmentPost(): void
    {
        $events = new ImplicitApplyEvents();
        $cloud = new ImplicitApplyCloud($events, []);
        $state = new ImplicitApplyState($events);

        $result = self::apply()->execute(self::blueprint(), self::fullPlan(), $cloud, $state);

        self::assertSame(ApplyStatus::SUCCESS, $result->status);
        self::assertSame(1, $cloud->environmentCreateCount);
        self::assertSame('env-created-production', $cloud->variableEnvironmentId);
        self::assertSame([
            'lock',
            'create:application',
            'save:application.lcb-e2e-test',
            'refresh:environments.app-created',
            'create:environment.production',
            'save:environment.production',
            'set:variables.env-created-production',
            'release',
        ], $events->values);
    }

    public function testMultipleImplicitNameMatchesFailSafelyBeforeEnvironmentPost(): void
    {
        $events = new ImplicitApplyEvents();
        $cloud = new ImplicitApplyCloud($events, [
            new CloudEnvironment('env-1', 'app-created', 'production', 'main'),
            new CloudEnvironment('env-2', 'app-created', 'production', 'main'),
        ]);

        $result = self::apply()->execute(
            self::blueprint(),
            self::fullPlan(),
            $cloud,
            new ImplicitApplyState($events),
        );

        self::assertSame(ApplyStatus::PARTIAL_FAILURE, $result->status);
        self::assertSame(0, $cloud->environmentCreateCount);
        self::assertNull($cloud->variableEnvironmentId);
        self::assertStringContainsString('Multiple environments', self::lastMessage($result));
    }

    public function testEnvironmentRefreshFailureKeepsApplicationCheckpointAndStops(): void
    {
        $events = new ImplicitApplyEvents();
        $cloud = new ImplicitApplyCloud($events, refreshFailure: true);
        $state = new ImplicitApplyState($events);

        $result = self::apply()->execute(self::blueprint(), self::fullPlan(), $cloud, $state);

        self::assertSame(ApplyStatus::PARTIAL_FAILURE, $result->status);
        self::assertNotNull($state->state->find(self::address(ResourceType::APPLICATION, 'lcb-e2e-test')));
        self::assertNull($state->state->find(self::address(ResourceType::ENVIRONMENT, 'production')));
        self::assertSame(0, $cloud->environmentCreateCount);
        self::assertNull($cloud->variableEnvironmentId);
    }

    public function testImplicitEnvironmentCheckpointFailureStopsVariableMutation(): void
    {
        $events = new ImplicitApplyEvents();
        $cloud = new ImplicitApplyCloud($events, [
            new CloudEnvironment('env-implicit', 'app-created', 'production', 'main'),
        ]);
        $state = new ImplicitApplyState($events, failSaveNumber: 2);

        $result = self::apply()->execute(self::blueprint(), self::fullPlan(), $cloud, $state);

        self::assertSame(ApplyStatus::PARTIAL_FAILURE, $result->status);
        self::assertNotNull($state->state->find(self::address(ResourceType::APPLICATION, 'lcb-e2e-test')));
        self::assertNull($state->state->find(self::address(ResourceType::ENVIRONMENT, 'production')));
        self::assertNull($cloud->variableEnvironmentId);
    }

    public function testOriginalNoChangeEnvironmentIsStillNotImported(): void
    {
        $events = new ImplicitApplyEvents();
        $cloud = new ImplicitApplyCloud($events);
        $state = new ImplicitApplyState($events);
        $plan = new ExecutionPlan(
            self::action(ResourceType::APPLICATION, 'lcb-e2e-test', PlanOperation::NO_CHANGE, 'app-existing'),
            self::action(ResourceType::ENVIRONMENT, 'production', PlanOperation::NO_CHANGE, 'env-existing'),
            self::action(ResourceType::VARIABLE, 'production.LCB_TEST', PlanOperation::NO_CHANGE),
        );

        $result = self::apply()->execute(self::blueprint(), $plan, $cloud, $state);

        self::assertSame(ApplyStatus::SUCCESS, $result->status);
        self::assertSame([], $state->state->resources());
        self::assertSame(['lock', 'release'], $events->values);
    }

    public function testExistingApplicationWithMissingEnvironmentStillUsesEnvironmentPostWithoutRefresh(): void
    {
        $events = new ImplicitApplyEvents();
        $cloud = new ImplicitApplyCloud($events);
        $state = new ImplicitApplyState($events, StateDocument::empty()->withOrganization('acme')->withResource(
            new StateResource(
                self::address(ResourceType::APPLICATION, 'lcb-e2e-test'),
                ResourceType::APPLICATION,
                'app-existing',
            ),
        ));
        $plan = new ExecutionPlan(
            self::action(ResourceType::APPLICATION, 'lcb-e2e-test', PlanOperation::NO_CHANGE, 'app-existing'),
            self::action(ResourceType::ENVIRONMENT, 'production', PlanOperation::CREATE),
            self::action(ResourceType::VARIABLE, 'production.LCB_TEST', PlanOperation::CREATE),
        );

        $result = self::apply()->execute(self::blueprint(), $plan, $cloud, $state);

        self::assertSame(ApplyStatus::SUCCESS, $result->status);
        self::assertSame(1, $cloud->environmentCreateCount);
        self::assertSame([
            'lock',
            'create:environment.production',
            'save:environment.production',
            'set:variables.env-created-production',
            'release',
        ], $events->values);
    }

    private static function apply(): CreateOnlyApply
    {
        return new CreateOnlyApply(new VariableValueResolver(new ImplicitApplyEnvironmentValues()));
    }

    private static function fullPlan(): ExecutionPlan
    {
        return new ExecutionPlan(
            self::action(ResourceType::APPLICATION, 'lcb-e2e-test', PlanOperation::CREATE),
            self::action(ResourceType::ENVIRONMENT, 'production', PlanOperation::CREATE),
            self::action(ResourceType::VARIABLE, 'production.LCB_TEST', PlanOperation::CREATE),
        );
    }

    private static function blueprint(): Blueprint
    {
        return new Blueprint(
            BlueprintSchemaVersion::V1,
            'acme',
            new ApplicationDefinition(
                'lcb-e2e-test',
                'eu-central-1',
                new SourceDefinition(SourceProvider::GITHUB, 'acme/lcb-e2e-test'),
            ),
            new EnvironmentDefinitionCollection(
                new EnvironmentDefinition(
                    'production',
                    'main',
                    new VariableDefinitionCollection(
                        new VariableDefinition('LCB_TEST', new LiteralVariableValue('secret-test-value'), true),
                    ),
                ),
            ),
        );
    }

    private static function action(
        ResourceType $type,
        string $name,
        PlanOperation $operation,
        ?string $remoteId = null,
    ): PlanAction {
        return new PlanAction(self::address($type, $name), $type, $operation, 'test', $remoteId);
    }

    private static function address(ResourceType $type, string $name): ResourceAddress
    {
        return new ResourceAddress($type, $name);
    }

    private static function lastMessage(\LaravelCloudBlueprint\Apply\ApplyResult $result): string
    {
        $outcomes = iterator_to_array($result, false);
        $last = end($outcomes);
        return $last === false ? '' : ($last->message ?? '');
    }
}

final class ImplicitApplyEvents
{
    /** @var list<string> */
    public array $values = [];
}

final readonly class ImplicitApplyEnvironmentValues implements EnvironmentValueProvider
{
    public function value(string $name): ?string { return null; }
}

final class ImplicitApplyCloud implements LaravelCloudClient
{
    public function updateEnvironment(string $environmentId, \LaravelCloudBlueprint\Cloud\DTO\UpdateEnvironmentRequest $request): \LaravelCloudBlueprint\Cloud\DTO\UpdatedCloudEnvironment
    {
        throw new \LogicException('Unexpected environment update.');
    }
    public int $environmentCreateCount = 0;
    public ?string $variableEnvironmentId = null;

    /** @param list<CloudEnvironment> $implicitEnvironments */
    public function __construct(
        private readonly ImplicitApplyEvents $events,
        private readonly array $implicitEnvironments = [],
        private readonly bool $refreshFailure = false,
    ) {
    }

    public function organization(): CloudOrganization { return new CloudOrganization('org-1', 'Acme', 'acme'); }
    public function applications(): array { return []; }
    public function environments(string $applicationId): array
    {
        $this->events->values[] = 'refresh:environments.' . $applicationId;
        if ($this->refreshFailure) {
            throw new CloudApiException('Environment refresh failed.', 'GET', '/applications/environments', 500);
        }
        return $this->implicitEnvironments;
    }
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
        ++$this->environmentCreateCount;
        $this->events->values[] = 'create:environment.' . $request->name;
        return new CloudEnvironment('env-created-' . $request->name, $applicationId, $request->name, $request->branch);
    }
    public function setEnvironmentVariables(string $environmentId, SetEnvironmentVariablesRequest $request): void
    {
        $this->variableEnvironmentId = $environmentId;
        $this->events->values[] = 'set:variables.' . $environmentId;
    }
}

final class ImplicitApplyState implements StateStore, StateTransaction
{
    private int $saveNumber = 0;

    public function __construct(
        private readonly ImplicitApplyEvents $events,
        public StateDocument $state = new StateDocument(\LaravelCloudBlueprint\State\StateVersion::V1, 0, null),
        private readonly ?int $failSaveNumber = null,
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
            throw new StateStorageException('checkpoint failed');
        }
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
