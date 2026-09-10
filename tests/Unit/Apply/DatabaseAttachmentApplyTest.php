<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Apply;

use LaravelCloudBlueprint\Apply\ApplyOutcome;
use LaravelCloudBlueprint\Apply\ApplyStatus;
use LaravelCloudBlueprint\Apply\CreateOnlyApply;
use LaravelCloudBlueprint\Apply\Exception\ApplyRefusedException;
use LaravelCloudBlueprint\Blueprint\ApplicationDefinition;
use LaravelCloudBlueprint\Blueprint\Blueprint;
use LaravelCloudBlueprint\Blueprint\BlueprintSchemaVersion;
use LaravelCloudBlueprint\Blueprint\DatabaseAttachmentIntent;
use LaravelCloudBlueprint\Blueprint\DatabaseClusterDefinition;
use LaravelCloudBlueprint\Blueprint\DatabaseClusterDefinitionCollection;
use LaravelCloudBlueprint\Blueprint\DatabaseClusterType;
use LaravelCloudBlueprint\Blueprint\DatabaseReference;
use LaravelCloudBlueprint\Blueprint\EnvironmentDefinition;
use LaravelCloudBlueprint\Blueprint\EnvironmentDefinitionCollection;
use LaravelCloudBlueprint\Blueprint\LaravelMySqlConfiguration;
use LaravelCloudBlueprint\Blueprint\LogicalDatabaseDefinition;
use LaravelCloudBlueprint\Blueprint\LogicalDatabaseDefinitionCollection;
use LaravelCloudBlueprint\Blueprint\SourceDefinition;
use LaravelCloudBlueprint\Blueprint\SourceProvider;
use LaravelCloudBlueprint\Blueprint\VariableDefinitionCollection;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudDatabaseAttachmentMutationClient;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudDatabaseClient;
use LaravelCloudBlueprint\Cloud\DTO\CloudApplication;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabase;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabaseCluster;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironment;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironmentDetails;
use LaravelCloudBlueprint\Cloud\DTO\CloudLaravelMySqlConfiguration;
use LaravelCloudBlueprint\Cloud\DTO\CloudOrganization;
use LaravelCloudBlueprint\Cloud\DTO\CreateApplicationRequest;
use LaravelCloudBlueprint\Cloud\DTO\CreateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\DTO\EnvironmentDependencies;
use LaravelCloudBlueprint\Cloud\DTO\SetEnvironmentVariablesRequest;
use LaravelCloudBlueprint\Cloud\DTO\UpdatedCloudEnvironment;
use LaravelCloudBlueprint\Cloud\DTO\UpdateEnvironmentDatabaseAttachmentRequest;
use LaravelCloudBlueprint\Cloud\DTO\UpdateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\Exception\CloudApiException;
use LaravelCloudBlueprint\Cloud\Exception\CloudException;
use LaravelCloudBlueprint\Cloud\Exception\CloudTransportException;
use LaravelCloudBlueprint\Planning\Contract\EnvironmentValueProvider;
use LaravelCloudBlueprint\Planning\CreatePlan;
use LaravelCloudBlueprint\Planning\ExecutionPlan;
use LaravelCloudBlueprint\Planning\PlanOperation;
use LaravelCloudBlueprint\Planning\PlanReconciliationStatus;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\Planning\VariableValueResolver;
use LaravelCloudBlueprint\State\Contract\StateStore;
use LaravelCloudBlueprint\State\Contract\StateTransaction;
use LaravelCloudBlueprint\State\StateDocument;
use LaravelCloudBlueprint\State\StateOwnershipClassification;
use LaravelCloudBlueprint\State\StateProvenance;
use LaravelCloudBlueprint\State\StateResource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DatabaseAttachmentApplyTest extends TestCase
{
    /** @return iterable<string, array{?string, DatabaseAttachmentIntent, ?string}> */
    public static function successfulMutations(): iterable
    {
        yield 'attach' => [null, DatabaseAttachmentIntent::attached(new DatabaseReference('primary', 'application')), 'database-1'];
        yield 'switch' => ['database-other', DatabaseAttachmentIntent::attached(new DatabaseReference('primary', 'application')), 'database-1'];
        yield 'detach' => ['database-other', DatabaseAttachmentIntent::detached(), null];
    }

    #[DataProvider('successfulMutations')]
    public function testAttachSwitchAndDetachAreConfirmedWithoutStateWrites(
        ?string $before,
        DatabaseAttachmentIntent $intent,
        ?string $expected,
    ): void {
        $cloud = new AttachmentCloud($before);
        $state = new AttachmentState(self::state());
        $blueprint = self::blueprint($intent);
        $serialized = serialize($state->state);

        $result = self::apply($blueprint, self::plan($blueprint, $cloud, $state->state), $cloud, $state);

        self::assertSame(ApplyStatus::SUCCESS, $result->status);
        self::assertSame(1, $result->updatedCount());
        self::assertSame(ApplyOutcome::UPDATED, self::attachmentOutcome($result)->outcome);
        self::assertSame([$expected], $cloud->patches);
        self::assertSame(['patch', 'confirm'], $cloud->mutationEvents);
        self::assertSame(0, $state->saveCount);
        self::assertSame($serialized, serialize($state->state));
    }

    public function testFreshAlreadyReconciledSendsNoPatch(): void
    {
        $cloud = new AttachmentCloud(null);
        $state = new AttachmentState(self::state());
        $blueprint = self::blueprint(DatabaseAttachmentIntent::attached(new DatabaseReference('primary', 'application')));
        $plan = self::plan($blueprint, $cloud, $state->state);
        $cloud->databaseId = 'database-1';

        $result = self::apply($blueprint, $plan, $cloud, $state);

        self::assertSame(ApplyStatus::SUCCESS, $result->status);
        self::assertSame(5, $result->unchangedCount());
        self::assertSame(ApplyOutcome::UNCHANGED, self::attachmentOutcome($result)->outcome);
        self::assertSame([], $cloud->patches);
        self::assertSame(0, $state->saveCount);
    }

    public function testLockedBlueprintIntentChangeRefusesWithoutPatch(): void
    {
        $cloud = new AttachmentCloud(null);
        $state = new AttachmentState(self::state());
        $approved = self::blueprint(DatabaseAttachmentIntent::attached(new DatabaseReference('primary', 'application')));
        $changed = self::blueprint(DatabaseAttachmentIntent::detached());

        $this->expectException(ApplyRefusedException::class);
        try {
            self::apply($approved, self::plan($approved, $cloud, $state->state), $cloud, $state, $changed);
        } finally {
            self::assertSame([], $cloud->patches);
        }
    }

    /** @return iterable<string, array{StateDocument}> */
    public static function identityRaces(): iterable
    {
        $withoutEnvironment = self::state()->withoutResource(new ResourceAddress(ResourceType::ENVIRONMENT, 'production'));
        yield 'environment unmanaged' => [$withoutEnvironment];

        $state = self::state();
        $database = new ResourceAddress(ResourceType::DATABASE, 'primary.application');
        yield 'database identity replaced' => [$state->withResource(new StateResource(
            $database,
            ResourceType::DATABASE,
            'database-replacement',
            new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary'),
        ))];

        yield 'database became derived' => [$state->withResource(new StateResource(
            $database,
            ResourceType::DATABASE,
            'database-1',
            new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary'),
            StateOwnershipClassification::DERIVED,
            StateProvenance::CLUSTER_CREATE_RESPONSE,
        ))];
    }

    #[DataProvider('identityRaces')]
    public function testLockedStateIdentityRaceRefusesWithoutPatch(StateDocument $changedState): void
    {
        $cloud = new AttachmentCloud(null);
        $blueprint = self::blueprint(DatabaseAttachmentIntent::attached(new DatabaseReference('primary', 'application')));
        $state = new AttachmentState(self::state());
        $plan = self::plan($blueprint, $cloud, $state->state);
        $state->state = $changedState;

        try {
            self::apply($blueprint, $plan, $cloud, $state);
            self::fail('Expected locked identity revalidation refusal.');
        } catch (ApplyRefusedException) {
            self::assertSame([], $cloud->patches);
        }
    }

    public function testIncompleteFreshRelationshipEvidenceRefusesWithoutPatch(): void
    {
        $cloud = new AttachmentCloud(null);
        $state = new AttachmentState(self::state());
        $blueprint = self::blueprint(DatabaseAttachmentIntent::attached(new DatabaseReference('primary', 'application')));
        $plan = self::plan($blueprint, $cloud, $state->state);
        $cloud->completeListRelationship = false;

        $this->expectException(ApplyRefusedException::class);
        try {
            self::apply($blueprint, $plan, $cloud, $state);
        } finally {
            self::assertSame([], $cloud->patches);
        }
    }

    public function testPatchSuccessWithConfirmationMismatchIsPostconditionFailureWithoutRetry(): void
    {
        $cloud = new AttachmentCloud(null);
        $cloud->confirmationDatabaseId = 'database-third';
        $cloud->confirmationOverride = true;
        $result = self::executeAttached($cloud);

        self::assertSame(ApplyStatus::FAILED, $result->status);
        self::assertSame(1, count($cloud->patches));
        self::assertSame(ApplyOutcome::POSTCONDITION_FAILED, self::attachmentOutcome($result)->outcome);
    }

    public function testTransportFailureConfirmedDesiredIsSuccessWithoutRetry(): void
    {
        $cloud = new AttachmentCloud(null);
        $cloud->patchFailure = new CloudTransportException('sentinel-token timeout', 'PATCH', '/environments/sentinel-env');
        $cloud->applyPatchBeforeFailure = true;

        $result = self::executeAttached($cloud);

        self::assertSame(ApplyStatus::SUCCESS, $result->status);
        self::assertSame(1, count($cloud->patches));
        self::assertSame(ApplyOutcome::UPDATED, self::attachmentOutcome($result)->outcome);
    }

    public function testTransportFailureWithAuthoritativePreviousRelationshipIsPostconditionFailureWithoutRetry(): void
    {
        $cloud = new AttachmentCloud(null);
        $cloud->patchFailure = new CloudTransportException('sentinel-token timeout', 'PATCH', '/environments/sentinel-env');
        $cloud->confirmationDatabaseId = null;
        $cloud->confirmationOverride = true;

        $result = self::executeAttached($cloud);

        self::assertSame(ApplyStatus::PARTIAL_FAILURE, $result->status);
        self::assertSame(1, count($cloud->patches));
        self::assertSame(ApplyOutcome::POSTCONDITION_FAILED, self::attachmentOutcome($result)->outcome);
        self::assertStringNotContainsString('sentinel-token', serialize($result));
    }

    public function testPatchFailureWithIncompleteOrFailedConfirmationIsUncertain(): void
    {
        $incomplete = new AttachmentCloud(null);
        $incomplete->patchFailure = new CloudTransportException('timeout', 'PATCH', '/environments/env-1');
        $incomplete->completeConfirmationRelationship = false;
        $incompleteResult = self::executeAttached($incomplete);

        self::assertSame(ApplyStatus::PARTIAL_FAILURE, $incompleteResult->status);
        self::assertCount(1, $incomplete->patches);
        self::assertSame(ApplyOutcome::UNCERTAIN, self::attachmentOutcome($incompleteResult)->outcome);

        $failed = new AttachmentCloud(null);
        $failed->patchFailure = new CloudTransportException('timeout', 'PATCH', '/environments/env-1');
        $failed->confirmationFailure = new CloudApiException('read failed', 'GET', '/environments/env-1', 500);
        $failedResult = self::executeAttached($failed);

        self::assertSame(ApplyStatus::PARTIAL_FAILURE, $failedResult->status);
        self::assertCount(1, $failed->patches);
        self::assertSame(ApplyOutcome::UNCERTAIN, self::attachmentOutcome($failedResult)->outcome);
    }

    public function testFiveHundredPatchFailureWithMismatchedPostconditionIsNeverRetried(): void
    {
        $cloud = new AttachmentCloud(null);
        $cloud->patchFailure = new CloudApiException('server failure', 'PATCH', '/environments/env-1', 503);

        $result = self::executeAttached($cloud);

        self::assertSame(ApplyStatus::PARTIAL_FAILURE, $result->status);
        self::assertCount(1, $cloud->patches);
        self::assertSame(ApplyOutcome::POSTCONDITION_FAILED, self::attachmentOutcome($result)->outcome);
    }

    /** @return iterable<string, array{int}> */
    public static function definitiveRefusals(): iterable
    {
        yield '403' => [403];
        yield '404' => [404];
        yield '422' => [422];
    }

    #[DataProvider('definitiveRefusals')]
    public function testDefinitiveRefusalIsNotRetried(int $status): void
    {
        $cloud = new AttachmentCloud(null);
        $cloud->patchFailure = new CloudApiException('sentinel-credential', 'PATCH', '/environments/sentinel-env', $status);

        $result = self::executeAttached($cloud);

        self::assertSame(ApplyStatus::FAILED, $result->status);
        self::assertCount(1, $cloud->patches);
        self::assertSame(ApplyOutcome::REFUSED, self::attachmentOutcome($result)->outcome);
        self::assertStringNotContainsString('sentinel-credential', serialize($result));
    }

    public function testBranchThenAttachmentUseSeparateMutationsAndPartialFailureIsTruthful(): void
    {
        $cloud = new AttachmentCloud(null, 'old-branch');
        $cloud->patchFailure = new CloudApiException('refused', 'PATCH', '/environments/env-1', 422);
        $state = new AttachmentState(self::state());
        $blueprint = self::blueprint(DatabaseAttachmentIntent::attached(new DatabaseReference('primary', 'application')));

        $result = self::apply($blueprint, self::plan($blueprint, $cloud, $state->state), $cloud, $state);

        self::assertSame(ApplyStatus::PARTIAL_FAILURE, $result->status);
        self::assertSame(['branch', 'patch', 'confirm'], $cloud->mutationEvents);
        self::assertSame(1, $result->updatedCount());
        self::assertSame(\LaravelCloudBlueprint\Apply\ApplyOutcomeOperation::FAILED, self::attachmentOutcome($result)->operation);
        self::assertSame(ApplyOutcome::REFUSED, self::attachmentOutcome($result)->outcome);
        self::assertSame('main', $cloud->branch);
    }

    private static function executeAttached(AttachmentCloud $cloud): \LaravelCloudBlueprint\Apply\ApplyResult
    {
        $state = new AttachmentState(self::state());
        $blueprint = self::blueprint(DatabaseAttachmentIntent::attached(new DatabaseReference('primary', 'application')));
        return self::apply($blueprint, self::plan($blueprint, $cloud, $state->state), $cloud, $state);
    }

    private static function attachmentOutcome(\LaravelCloudBlueprint\Apply\ApplyResult $result): \LaravelCloudBlueprint\Apply\ApplyResourceOutcome
    {
        foreach ($result as $outcome) {
            if ($outcome->address->type === ResourceType::DATABASE_ATTACHMENT) {
                return $outcome;
            }
        }
        throw new \LogicException('Missing Database attachment outcome.');
    }

    private static function apply(
        Blueprint $blueprint,
        ExecutionPlan $plan,
        AttachmentCloud $cloud,
        AttachmentState $state,
        ?Blueprint $lockedBlueprint = null,
    ): \LaravelCloudBlueprint\Apply\ApplyResult {
        return (new CreateOnlyApply(new VariableValueResolver(new AttachmentValues())))->execute(
            $blueprint,
            $plan,
            $cloud,
            $state,
            $lockedBlueprint === null ? null : static fn (): Blueprint => $lockedBlueprint,
        );
    }

    private static function plan(Blueprint $blueprint, AttachmentCloud $cloud, StateDocument $state): ExecutionPlan
    {
        $plan = (new CreatePlan(new VariableValueResolver(new AttachmentValues())))->create($blueprint, $cloud, $state);
        self::assertSame(PlanOperation::UPDATE, self::attachmentPlanAction($plan)->operation);
        self::assertSame(PlanReconciliationStatus::SUPPORTED, self::attachmentPlanAction($plan)->reconciliation);
        return $plan;
    }

    private static function attachmentPlanAction(ExecutionPlan $plan): \LaravelCloudBlueprint\Planning\PlanAction
    {
        foreach ($plan as $action) {
            if ($action->resourceType === ResourceType::DATABASE_ATTACHMENT) {
                return $action;
            }
        }
        throw new \LogicException('Missing Database attachment plan action.');
    }

    private static function state(): StateDocument
    {
        $application = new ResourceAddress(ResourceType::APPLICATION, 'my-api');
        $environment = new ResourceAddress(ResourceType::ENVIRONMENT, 'production');
        $cluster = new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary');
        return StateDocument::empty()->withOrganization('acme')
            ->withResource(new StateResource($application, ResourceType::APPLICATION, 'app-1'))
            ->withResource(new StateResource($environment, ResourceType::ENVIRONMENT, 'env-1', $application))
            ->withResource(new StateResource($cluster, ResourceType::DATABASE_CLUSTER, 'cluster-1'))
            ->withResource(new StateResource(
                new ResourceAddress(ResourceType::DATABASE, 'primary.application'),
                ResourceType::DATABASE,
                'database-1',
                $cluster,
            ))
            ->withSerial(19);
    }

    private static function blueprint(DatabaseAttachmentIntent $intent): Blueprint
    {
        return new Blueprint(
            BlueprintSchemaVersion::V1,
            'acme',
            new ApplicationDefinition('my-api', 'eu-central-1', new SourceDefinition(SourceProvider::GITHUB, 'acme/my-api')),
            new EnvironmentDefinitionCollection(new EnvironmentDefinition(
                'production', 'main', new VariableDefinitionCollection(), $intent,
            )),
            new DatabaseClusterDefinitionCollection(new DatabaseClusterDefinition(
                'primary',
                DatabaseClusterType::LARAVEL_MYSQL_8,
                'eu-central-1',
                new LaravelMySqlConfiguration('db-flex.m-1vcpu-512mb', 5, 1, false, false),
                new LogicalDatabaseDefinitionCollection(new LogicalDatabaseDefinition('application')),
            )),
        );
    }
}

final readonly class AttachmentValues implements EnvironmentValueProvider
{
    public function value(string $name): ?string { return null; }
}

final class AttachmentState implements StateStore, StateTransaction
{
    public int $saveCount = 0;
    public function __construct(public StateDocument $state) {}
    public function load(): StateDocument { return $this->state; }
    public function save(StateDocument $state): StateDocument { ++$this->saveCount; return $this->state = $state; }
    public function begin(): StateTransaction { return $this; }
    public function release(): void {}
}

final class AttachmentCloud implements LaravelCloudDatabaseClient, LaravelCloudDatabaseAttachmentMutationClient
{
    /** @var list<?string> */
    public array $patches = [];
    /** @var list<string> */
    public array $mutationEvents = [];
    public ?string $confirmationDatabaseId = null;
    public bool $confirmationOverride = false;
    public bool $completeListRelationship = true;
    public bool $completeConfirmationRelationship = true;
    public ?CloudException $patchFailure = null;
    public ?CloudException $confirmationFailure = null;
    public bool $applyPatchBeforeFailure = false;

    public function __construct(public ?string $databaseId, public string $branch = 'main') {}
    public function organization(): CloudOrganization { return new CloudOrganization('org-1', 'Acme', 'acme'); }
    public function applications(): array { return [new CloudApplication('app-1', 'my-api', 'my-api', 'eu-central-1', 'acme/my-api')]; }
    public function environments(string $applicationId): array
    {
        return [new CloudEnvironment(
            'env-1', $applicationId, 'production', $this->branch, $this->databaseId,
            $this->completeListRelationship
                ? EnvironmentDependencies::authoritativeDatabaseRelationship($this->databaseId)
                : EnvironmentDependencies::incomplete($this->databaseId),
        )];
    }
    public function environment(string $environmentId): CloudEnvironmentDetails
    {
        $this->mutationEvents[] = 'confirm';
        if ($this->confirmationFailure !== null) {
            throw $this->confirmationFailure;
        }
        $databaseId = $this->confirmationOverride ? $this->confirmationDatabaseId : $this->databaseId;
        return new CloudEnvironmentDetails(
            $environmentId,
            'production',
            null,
            $databaseId,
            $this->completeConfirmationRelationship
                ? EnvironmentDependencies::authoritativeDatabaseRelationship($databaseId)
                : EnvironmentDependencies::incomplete($databaseId),
        );
    }
    public function databaseClusters(): array
    {
        return [new CloudDatabaseCluster(
            'cluster-1', 'primary', 'laravel_mysql_8', 'available', 'eu-central-1',
            new CloudLaravelMySqlConfiguration('db-flex.m-1vcpu-512mb', 5, 1, false, false),
        )];
    }
    public function databaseCluster(string $clusterId): CloudDatabaseCluster { return $this->databaseClusters()[0]; }
    public function databases(string $clusterId): array { return [new CloudDatabase('database-1', 'cluster-1', 'application')]; }
    public function database(string $clusterId, string $databaseId): CloudDatabase { return $this->databases($clusterId)[0]; }
    public function databaseWithDestructiveRelationships(string $clusterId, string $databaseId): CloudDatabase { return $this->database($clusterId, $databaseId); }
    public function updateEnvironmentDatabaseAttachment(string $environmentId, UpdateEnvironmentDatabaseAttachmentRequest $request): UpdatedCloudEnvironment
    {
        $this->patches[] = $request->databaseId;
        $this->mutationEvents[] = 'patch';
        if ($this->patchFailure !== null) {
            if ($this->applyPatchBeforeFailure) { $this->databaseId = $request->databaseId; }
            throw $this->patchFailure;
        }
        $this->databaseId = $request->databaseId;
        return new UpdatedCloudEnvironment($environmentId);
    }
    public function createApplication(CreateApplicationRequest $request): CloudApplication { throw new \LogicException('Unexpected create.'); }
    public function createEnvironment(string $applicationId, CreateEnvironmentRequest $request): CloudEnvironment { throw new \LogicException('Unexpected create.'); }
    public function updateEnvironment(string $environmentId, UpdateEnvironmentRequest $request): UpdatedCloudEnvironment
    {
        $this->mutationEvents[] = 'branch';
        $this->branch = $request->branch;
        return new UpdatedCloudEnvironment($environmentId);
    }
    public function setEnvironmentVariables(string $environmentId, SetEnvironmentVariablesRequest $request): void { throw new \LogicException('Unexpected variables.'); }
}
