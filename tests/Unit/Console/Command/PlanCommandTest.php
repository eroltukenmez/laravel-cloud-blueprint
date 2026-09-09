<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Console\Command;

use LaravelCloudBlueprint\Application\BlueprintLoader;
use LaravelCloudBlueprint\Application\File\FileReader;
use LaravelCloudBlueprint\Blueprint\Normalization\BlueprintNormalizer;
use LaravelCloudBlueprint\Blueprint\Validation\BlueprintValidator;
use LaravelCloudBlueprint\Cloud\CloudApiToken;
use LaravelCloudBlueprint\Cloud\Contract\CloudTokenProvider;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudClient;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudClientFactory;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudDatabaseLifecycleClient;
use LaravelCloudBlueprint\Cloud\DTO\CloudApplication;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabase;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabaseCluster;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabaseSnapshot;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironment;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironmentDetails;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironmentVariable;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironmentVariableCollection;
use LaravelCloudBlueprint\Cloud\DTO\CloudOrganization;
use LaravelCloudBlueprint\Cloud\DTO\CloudLaravelMySqlConfiguration;
use LaravelCloudBlueprint\Cloud\DTO\DatabaseSnapshotStatus;
use LaravelCloudBlueprint\Cloud\DTO\DatabaseSnapshotType;
use LaravelCloudBlueprint\Cloud\DTO\CreateApplicationRequest;
use LaravelCloudBlueprint\Cloud\DTO\CreateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\DTO\EnvironmentDependencies;
use LaravelCloudBlueprint\Cloud\DTO\SetEnvironmentVariablesRequest;
use LaravelCloudBlueprint\Cloud\Exception\CloudApiException;
use LaravelCloudBlueprint\Console\Command\PlanCommand;
use LaravelCloudBlueprint\Console\ExitCode;
use LaravelCloudBlueprint\Infrastructure\Yaml\SymfonyYamlDecoder;
use LaravelCloudBlueprint\Planning\CreatePlan;
use LaravelCloudBlueprint\Planning\Contract\EnvironmentValueProvider;
use LaravelCloudBlueprint\Planning\VariableValueResolver;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\State\Contract\StateStore;
use LaravelCloudBlueprint\State\Contract\StateTransaction;
use LaravelCloudBlueprint\State\StateDocument;
use LaravelCloudBlueprint\State\StateOwnershipClassification;
use LaravelCloudBlueprint\State\StateProvenance;
use LaravelCloudBlueprint\State\StateResource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use LogicException;

final class PlanCommandTest extends TestCase
{
    public function testAddressCollisionBlueprintIsRejectedBeforePlanning(): void
    {
        $tester = $this->tester(self::addressCollisionBlueprint(), null);

        self::assertSame(ExitCode::BLUEPRINT_ERROR->value, $tester->execute([]));
        self::assertStringContainsString('Variable logical key "bar.baz" must not contain dots.', $tester->getDisplay());
    }

    public function testItRendersATextPlanWithoutExposingTheToken(): void
    {
        $tester = $this->tester(self::validBlueprint(applicationName: 'new-api'));

        self::assertSame(ExitCode::SUCCESS->value, $tester->execute([]));
        self::assertStringContainsString('Laravel Cloud Blueprint Plan', $tester->getDisplay());
        self::assertStringContainsString('+ application.new-api', $tester->getDisplay());
        self::assertStringContainsString('+ environment.production', $tester->getDisplay());
        self::assertStringContainsString('Reconciliation: supported.', $tester->getDisplay());
        self::assertStringContainsString('Plan: 2 to create, 0 to update, 0 unchanged, 0 unsupported.', $tester->getDisplay());
        self::assertStringNotContainsString('super-secret-token', $tester->getDisplay());
    }

    public function testJsonModeProducesAStableStructuredPlan(): void
    {
        $tester = $this->tester(self::validBlueprint(applicationName: 'new-api'));

        self::assertSame(ExitCode::SUCCESS->value, $tester->execute(['--json' => true]));
        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('success', $decoded['status']);
        self::assertIsArray($decoded['summary']);
        self::assertSame(2, $decoded['summary']['create']);
        self::assertSame(0, $decoded['summary']['update']);
        self::assertIsArray($decoded['actions']);
        self::assertIsArray($decoded['actions'][0]);
        self::assertSame('application.new-api', $decoded['actions'][0]['resource']);
        self::assertSame('create', $decoded['actions'][0]['operation']);
        self::assertSame('supported', $decoded['actions'][0]['reconciliation']);
        self::assertSame('none', $decoded['actions'][0]['ownership']);
        foreach ($decoded['actions'] as $action) {
            self::assertIsArray($action);
            self::assertArrayHasKey('reconciliation', $action);
            self::assertArrayHasKey('ownership', $action);
        }
    }

    public function testUnmanagedNoChangeRemainsVisibleThroughTypedOwnership(): void
    {
        $text = $this->tester(self::validBlueprint());

        self::assertSame(ExitCode::SUCCESS->value, $text->execute([]));
        self::assertStringNotContainsString('No changes.', $text->getDisplay());
        self::assertStringContainsString('Matching remote application is unmanaged', $text->getDisplay());

        $json = $this->tester(self::validBlueprint());
        self::assertSame(ExitCode::SUCCESS->value, $json->execute(['--json' => true]));
        $decoded = json_decode($json->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['actions']);
        self::assertIsArray($decoded['actions'][0]);
        self::assertSame('unmanaged', $decoded['actions'][0]['ownership']);
        self::assertSame('not_applicable', $decoded['actions'][0]['reconciliation']);
    }

    public function testManagedNoChangeKeepsTheExistingConciseHumanOutput(): void
    {
        $tester = $this->tester(self::validBlueprint(), state: self::managedState());

        self::assertSame(ExitCode::SUCCESS->value, $tester->execute([]));
        self::assertStringContainsString(
            "No changes.\n\nLaravel Cloud infrastructure matches the blueprint.",
            $tester->getDisplay(),
        );
    }

    public function testUpdateTextAndJsonRenderSafeChangesAndNeverVariableValues(): void
    {
        $blueprint = self::blueprintWithUpdate();
        $text = $this->tester($blueprint, cloud: new PlanUpdateCloudClient(), state: self::managedState());

        self::assertSame(ExitCode::SUCCESS->value, $text->execute([]));
        self::assertStringContainsString('! application.API', $text->getDisplay());
        self::assertStringContainsString('Application repository differs and cannot be updated safely.', $text->getDisplay());
        self::assertStringContainsString('branch: old-branch → main', $text->getDisplay());
        self::assertStringContainsString('Environment variable differs from desired state.', $text->getDisplay());
        self::assertStringContainsString('Plan: 0 to create, 2 to update, 0 unchanged, 1 unsupported.', $text->getDisplay());

        $json = $this->tester($blueprint, cloud: new PlanUpdateCloudClient(), state: self::managedState());
        self::assertSame(ExitCode::SUCCESS->value, $json->execute(['--json' => true]));
        $decoded = json_decode($json->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['summary']);
        self::assertIsArray($decoded['actions']);
        self::assertIsArray($decoded['actions'][0]);
        self::assertIsArray($decoded['actions'][1]);
        self::assertIsArray($decoded['actions'][1]['changes']);
        self::assertIsArray($decoded['actions'][1]['changes'][0]);
        self::assertIsArray($decoded['actions'][2]);
        self::assertSame(2, $decoded['summary']['update']);
        self::assertSame(1, $decoded['summary']['unsupported']);
        self::assertSame('unsupported', $decoded['actions'][0]['operation']);
        self::assertArrayNotHasKey('changes', $decoded['actions'][0]);
        self::assertSame('branch', $decoded['actions'][1]['changes'][0]['field']);
        self::assertArrayNotHasKey('changes', $decoded['actions'][2]);

        foreach ([$text->getDisplay(), $json->getDisplay()] as $output) {
            self::assertStringNotContainsString('desired-variable-secret', $output);
            self::assertStringNotContainsString('remote-variable-secret', $output);
        }
    }

    public function testMissingTokenFailsSafely(): void
    {
        $tester = $this->tester(self::validBlueprint(), null);

        self::assertSame(ExitCode::GENERAL_ERROR->value, $tester->execute([]));
        self::assertStringContainsString('LCB_TOKEN is not set.', $tester->getDisplay());
    }

    public function testJsonErrorsRemainStructuredForMissingBlueprintTokenValidationAndCloudFailure(): void
    {
        $missing = $this->tester(self::validBlueprint(), fileExists: false);
        self::assertSame(ExitCode::GENERAL_ERROR->value, $missing->execute(['--json' => true]));
        self::assertJsonError($missing, 'does not exist');

        $token = $this->tester(self::validBlueprint(), null);
        self::assertSame(ExitCode::GENERAL_ERROR->value, $token->execute(['--json' => true]));
        self::assertJsonError($token, 'LCB_TOKEN is not set.');

        $validation = $this->tester("version: 1\n", null);
        self::assertSame(ExitCode::BLUEPRINT_ERROR->value, $validation->execute(['--json' => true]));
        $validationJson = json_decode($validation->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($validationJson);
        self::assertSame('validation_failed', $validationJson['status']);
        self::assertIsArray($validationJson['errors']);

        $malformed = $this->tester("application:\n  name: example\n invalid: indentation\n", null);
        self::assertSame(ExitCode::BLUEPRINT_ERROR->value, $malformed->execute(['--json' => true]));
        self::assertJsonError($malformed, 'Blueprint YAML could not be decoded.');

        $cloud = $this->tester(self::validBlueprint(), cloud: new PlanThrowingCloudClient());
        self::assertSame(ExitCode::GENERAL_ERROR->value, $cloud->execute(['--json' => true]));
        self::assertJsonError($cloud, 'Laravel Cloud is unavailable.');

        foreach ([$missing, $token, $validation, $malformed, $cloud] as $tester) {
            self::assertStringNotContainsString('<error>', $tester->getDisplay());
            self::assertStringNotContainsString('super-secret-token', $tester->getDisplay());
        }
    }

    public function testJsonEncodingFailureProducesSafeValidJson(): void
    {
        $tester = $this->tester(
            self::validBlueprint(),
            cloud: new PlanInvalidUtf8CloudClient(),
            state: self::managedState(),
        );

        self::assertSame(ExitCode::GENERAL_ERROR->value, $tester->execute(['--json' => true]));
        self::assertJsonError($tester, 'Unable to encode command output as JSON.');
        self::assertStringNotContainsString('<error>', $tester->getDisplay());
    }

    public function testInvalidBlueprintReturnsBlueprintErrorBeforeTokenLookup(): void
    {
        $tester = $this->tester("version: 1\n", null);

        self::assertSame(ExitCode::BLUEPRINT_ERROR->value, $tester->execute([]));
        self::assertStringContainsString('[required] organization:', $tester->getDisplay());
    }

    public function testCloudExceptionsAreRenderedSafely(): void
    {
        $token = new CloudApiToken('super-secret-token');
        $tester = $this->tester(
            self::validBlueprint(),
            $token,
            new PlanThrowingCloudClient(),
        );

        self::assertSame(ExitCode::GENERAL_ERROR->value, $tester->execute([]));
        self::assertStringContainsString('Laravel Cloud is unavailable.', $tester->getDisplay());
        self::assertStringNotContainsString($token->value(), $tester->getDisplay());
    }

    public function testTextAndJsonPlansExposeVariableKeysButNeverVariableValues(): void
    {
        $text = $this->tester(self::blueprintWithSecrets());
        self::assertSame(ExitCode::SUCCESS->value, $text->execute([]));
        self::assertStringContainsString('variable.production.APP_LITERAL', $text->getDisplay());
        self::assertStringContainsString('variable.production.APP_KEY', $text->getDisplay());

        $json = $this->tester(self::blueprintWithSecrets());
        self::assertSame(ExitCode::SUCCESS->value, $json->execute(['--json' => true]));
        $decoded = json_decode($json->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        foreach ([$text->getDisplay(), $json->getDisplay()] as $output) {
            self::assertStringNotContainsString('literal-super-secret', $output);
            self::assertStringNotContainsString('resolved-super-secret', $output);
            self::assertStringNotContainsString('LOCAL_APP_KEY', $output);
        }
    }

    public function testOwnedOnlyLifecycleActionPreservesTextAndJsonContractsAndExitZero(): void
    {
        $desiredApplication = new ResourceAddress(ResourceType::APPLICATION, 'API');
        $oldApplication = new ResourceAddress(ResourceType::APPLICATION, 'old-api');
        $state = StateDocument::empty()->withOrganization('acme')
            ->withResource(new StateResource($oldApplication, ResourceType::APPLICATION, 'app-old'))
            ->withResource(new StateResource(
                new ResourceAddress(ResourceType::ENVIRONMENT, 'preview'),
                ResourceType::ENVIRONMENT,
                'env-preview',
                $oldApplication,
            ))
            ->withResource(new StateResource($desiredApplication, ResourceType::APPLICATION, 'app-1'));

        $text = $this->tester(self::validBlueprint(), state: $state);
        self::assertSame(ExitCode::SUCCESS->value, $text->execute([]));
        self::assertStringContainsString('- application.old-api', $text->getDisplay());
        self::assertStringContainsString('- environment.preview', $text->getDisplay());
        self::assertStringContainsString('Reconciliation: blocked.', $text->getDisplay());
        self::assertStringContainsString('Reconciliation: unsupported.', $text->getDisplay());
        self::assertStringContainsString('Plan: 0 to create, 0 to update, 2 to delete, 2 unchanged, 0 unsupported.', $text->getDisplay());

        $json = $this->tester(self::validBlueprint(), state: $state);
        self::assertSame(ExitCode::SUCCESS->value, $json->execute(['--json' => true]));
        $decoded = json_decode($json->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $summary = $decoded['summary'] ?? null;
        $actions = $decoded['actions'] ?? null;
        self::assertIsArray($summary);
        self::assertIsArray($actions);
        self::assertSame(2, $summary['delete']);
        self::assertSame(0, $summary['unsupported']);
        self::assertSame([
            'environment.preview',
            'application.old-api',
            'application.API',
            'environment.production',
        ], array_column($actions, 'resource'));
        self::assertIsArray($actions[0]);
        self::assertIsArray($actions[1]);
        self::assertSame('delete', $actions[1]['operation']);
        self::assertSame('unsupported', $actions[1]['reconciliation']);
        self::assertSame('managed', $actions[1]['ownership']);
        self::assertIsString($actions[1]['reason']);
        self::assertStringContainsString('exact recorded remote identity is already missing', $actions[1]['reason']);
        self::assertSame('application.old-api', $actions[0]['parent']);

        foreach ($actions as $action) {
            self::assertIsArray($action);
            self::assertArrayNotHasKey('remote_id', $action);
            self::assertArrayNotHasKey('lifecycle_status', $action);
            self::assertArrayHasKey('ownership', $action);
            self::assertArrayNotHasKey('desired', $action);
        }
    }

    public function testEnvironmentDeleteRendersSafeDependencyReadinessInTextAndJson(): void
    {
        $application = new ResourceAddress(ResourceType::APPLICATION, 'API');
        $preview = new ResourceAddress(ResourceType::ENVIRONMENT, 'preview');
        $state = StateDocument::empty()->withOrganization('acme')
            ->withResource(new StateResource($application, ResourceType::APPLICATION, 'app-1'))
            ->withResource(new StateResource($preview, ResourceType::ENVIRONMENT, 'env-preview', $application));
        $cloud = new PlanDependencyCloudClient();

        $text = $this->tester(self::validBlueprint(), cloud: $cloud, state: $state);
        self::assertSame(ExitCode::SUCCESS->value, $text->execute([]));
        self::assertStringContainsString('database_attachment, custom_domain', $text->getDisplay());
        self::assertStringContainsString('Reconciliation: blocked.', $text->getDisplay());
        self::assertStringNotContainsString('database-internal-id', $text->getDisplay());

        $json = $this->tester(self::validBlueprint(), cloud: $cloud, state: $state);
        self::assertSame(ExitCode::SUCCESS->value, $json->execute(['--json' => true]));
        $decoded = json_decode($json->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['actions']);
        self::assertIsArray($decoded['actions'][0]);
        self::assertSame('blocked', $decoded['actions'][0]['destructive_readiness']);
        self::assertSame('blocked', $decoded['actions'][0]['reconciliation']);
        self::assertSame(['database_attachment', 'custom_domain'], $decoded['actions'][0]['dependencies']);
        self::assertSame(['database_attachment', 'custom_domain'], $decoded['actions'][0]['blocking_dependencies']);
        self::assertSame([], $decoded['actions'][0]['informational_dependencies']);
        self::assertSame([], $decoded['actions'][0]['missing_dependency_relationships']);
        self::assertSame([], $decoded['actions'][0]['unknown_dependency_relationships']);
        self::assertStringNotContainsString('database-internal-id', $json->getDisplay());
    }

    public function testEnvironmentDeleteSerializesInstanceAsInformationalExpectedChild(): void
    {
        $application = new ResourceAddress(ResourceType::APPLICATION, 'API');
        $preview = new ResourceAddress(ResourceType::ENVIRONMENT, 'preview');
        $state = StateDocument::empty()->withOrganization('acme')
            ->withResource(new StateResource($application, ResourceType::APPLICATION, 'app-1'))
            ->withResource(new StateResource($preview, ResourceType::ENVIRONMENT, 'env-preview', $application));
        $json = $this->tester(self::validBlueprint(), cloud: new PlanInstanceCloudClient(), state: $state);

        self::assertSame(ExitCode::SUCCESS->value, $json->execute(['--json' => true]));
        $decoded = json_decode($json->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['actions']);
        self::assertIsArray($decoded['actions'][0]);
        $action = $decoded['actions'][0];
        self::assertSame('safe', $action['destructive_readiness']);
        self::assertSame('supported', $action['reconciliation']);
        self::assertSame(['instance'], $action['dependencies']);
        self::assertSame([], $action['blocking_dependencies']);
        self::assertSame(['instance'], $action['informational_dependencies']);
        self::assertSame([], $action['missing_dependency_relationships']);
        self::assertSame([], $action['unknown_dependency_relationships']);
        self::assertIsString($action['reason']);
        self::assertStringContainsString('Expected child dependencies: instance', $action['reason']);
    }

    public function testDatabasePlanUsesExistingTextAndJsonContractsWithoutRemoteIds(): void
    {
        $text = $this->tester(self::databaseBlueprint(), cloud: new PlanDatabaseCloudClient());
        self::assertSame(ExitCode::SUCCESS->value, $text->execute([]));
        self::assertStringContainsString('= database_cluster.primary', $text->getDisplay());
        self::assertStringContainsString('= database.primary.application', $text->getDisplay());
        self::assertStringContainsString('! database_attachment.production', $text->getDisplay());
        self::assertStringNotContainsString('cluster-secret-id', $text->getDisplay());
        self::assertStringNotContainsString('database-secret-id', $text->getDisplay());

        $json = $this->tester(self::databaseBlueprint(), cloud: new PlanDatabaseCloudClient());
        self::assertSame(ExitCode::SUCCESS->value, $json->execute(['--json' => true]));
        $decoded = json_decode($json->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['summary']);
        self::assertSame(4, $decoded['summary']['no_change']);
        self::assertSame(1, $decoded['summary']['unsupported']);
        self::assertIsArray($decoded['actions']);
        self::assertIsArray($decoded['actions'][2]);
        self::assertIsArray($decoded['actions'][3]);
        self::assertIsArray($decoded['actions'][4]);
        self::assertSame('database_cluster', $decoded['actions'][2]['type']);
        self::assertSame('database', $decoded['actions'][3]['type']);
        self::assertSame('database_attachment', $decoded['actions'][4]['type']);
        self::assertStringNotContainsString('cluster-secret-id', $json->getDisplay());
        self::assertStringNotContainsString('database-secret-id', $json->getDisplay());
    }

    public function testDerivedDefaultDatabaseIsVisibleInTextAndJsonWithoutRemoteIdentity(): void
    {
        $cluster = new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary');
        $state = StateDocument::empty()->withOrganization('acme')
            ->withResource(new StateResource($cluster, ResourceType::DATABASE_CLUSTER, 'cluster-secret-id'))
            ->withResource(new StateResource(
                new ResourceAddress(ResourceType::DATABASE, 'primary.application'),
                ResourceType::DATABASE,
                'database-secret-id',
                $cluster,
            ))
            ->withResource(new StateResource(
                new ResourceAddress(ResourceType::DATABASE, 'primary.__derived_default'),
                ResourceType::DATABASE,
                'derived-secret-id',
                $cluster,
                StateOwnershipClassification::DERIVED,
                StateProvenance::CLUSTER_CREATE_RESPONSE,
            ));
        $cloud = new PlanDatabaseCloudClient(databases: [
            new CloudDatabase('database-secret-id', 'cluster-secret-id', 'application'),
            new CloudDatabase('derived-secret-id', 'cluster-secret-id', 'any-cloud-name', 'cluster-secret-id'),
        ]);

        $text = $this->tester(self::databaseBlueprint(), cloud: $cloud, state: $state);
        self::assertSame(ExitCode::SUCCESS->value, $text->execute([]));
        self::assertStringContainsString('= database.primary.__derived_default', $text->getDisplay());
        self::assertStringContainsString('retained as derived infrastructure', $text->getDisplay());

        $json = $this->tester(self::databaseBlueprint(), cloud: $cloud, state: $state);
        self::assertSame(ExitCode::SUCCESS->value, $json->execute(['--json' => true]));
        $decoded = json_decode($json->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['actions']);
        $derived = array_values(array_filter(
            $decoded['actions'],
            static fn ($action): bool => is_array($action)
                && ($action['resource'] ?? null) === 'database.primary.__derived_default',
        ));
        self::assertCount(1, $derived);
        self::assertSame('derived', $derived[0]['classification']);
        self::assertSame('cluster_create_response', $derived[0]['provenance']);
        self::assertSame('parent_lifecycle_dependency', $derived[0]['destructive_role']);

        foreach ([$text->getDisplay(), $json->getDisplay()] as $output) {
            self::assertStringNotContainsString('derived-secret-id', $output);
            self::assertStringNotContainsString('any-cloud-name', $output);
        }
    }

    public function testClusterDerivedDependencyDiagnosticsAreSeparatedAndSanitized(): void
    {
        $cluster = new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary');
        $state = StateDocument::empty()->withOrganization('acme')
            ->withResource(new StateResource($cluster, ResourceType::DATABASE_CLUSTER, 'cluster-secret-id'))
            ->withResource(new StateResource(
                new ResourceAddress(ResourceType::DATABASE, 'primary.__derived_default'),
                ResourceType::DATABASE,
                'derived-secret-id',
                $cluster,
                StateOwnershipClassification::DERIVED,
                StateProvenance::CLUSTER_CREATE_RESPONSE,
            ));
        $cloud = new PlanDatabaseCloudClient(databases: [
            new CloudDatabase('derived-secret-id', 'cluster-secret-id', 'any-cloud-name', 'cluster-secret-id'),
        ]);

        $text = $this->tester(self::validBlueprint(), cloud: $cloud, state: $state);
        self::assertSame(ExitCode::SUCCESS->value, $text->execute([]));
        self::assertStringContainsString('Structural readiness: satisfied.', $text->getDisplay());
        self::assertStringContainsString('Derived parent dependencies: 1.', $text->getDisplay());
        self::assertStringContainsString('eligible only within guarded parent destruction', $text->getDisplay());
        self::assertStringContainsString('Will be deleted only during this approved guarded parent lifecycle.', $text->getDisplay());
        self::assertStringNotContainsString('Database Cluster DELETE execution is not supported', $text->getDisplay());

        $json = $this->tester(self::validBlueprint(), cloud: $cloud, state: $state);
        self::assertSame(ExitCode::SUCCESS->value, $json->execute(['--json' => true]));
        $decoded = json_decode($json->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $actions = $decoded['actions'] ?? null;
        self::assertIsArray($actions);
        $clusterActions = array_values(array_filter(
            $actions,
            static fn ($action): bool => is_array($action)
                && ($action['resource'] ?? null) === 'database_cluster.primary',
        ));
        self::assertCount(1, $clusterActions);
        self::assertSame([
            'address' => 'database.primary.__derived_default',
            'classification' => 'derived',
            'provenance' => 'cluster_create_response',
            'destructive_role' => 'parent_lifecycle_dependency',
            'planned_lifecycle_effect' => 'delete_during_guarded_parent_lifecycle',
        ], $clusterActions[0]['parent_lifecycle_dependency']);
        $clusterAction = $clusterActions[0];
        self::assertSame('satisfied', $clusterAction['structural_readiness']);
        self::assertSame(1, $clusterAction['derived_parent_dependency_count']);
        $informational = $clusterAction['informational_dependencies'] ?? null;
        $blocking = $clusterAction['blocking_dependencies'] ?? null;
        self::assertIsArray($informational);
        self::assertIsArray($blocking);
        self::assertContains('derived_parent_dependency', $informational);
        self::assertNotContains('derived_parent_dependency', $blocking);

        foreach ([$text->getDisplay(), $json->getDisplay()] as $output) {
            self::assertStringNotContainsString('derived-secret-id', $output);
            self::assertStringNotContainsString('any-cloud-name', $output);
            self::assertStringNotContainsString('cluster-secret-id', $output);
        }
    }

    public function testDatabaseDeleteReadinessIsRenderedWithoutDependencyIdsOrSecrets(): void
    {
        $cluster = new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary');
        $state = StateDocument::empty()->withOrganization('acme')
            ->withResource(new StateResource($cluster, ResourceType::DATABASE_CLUSTER, 'cluster-secret-id'))
            ->withResource(new StateResource(
                new ResourceAddress(ResourceType::DATABASE, 'primary.application'),
                ResourceType::DATABASE,
                'database-secret-id',
                $cluster,
            ));

        $text = $this->tester(self::validBlueprint(), cloud: new PlanDatabaseCloudClient(), state: $state);
        self::assertSame(ExitCode::SUCCESS->value, $text->execute([]));
        self::assertStringContainsString('Destructive readiness: blocked', $text->getDisplay());
        self::assertStringContainsString('guarded deletion is blocked', $text->getDisplay());
        self::assertStringNotContainsString('Database DELETE execution is not supported', $text->getDisplay());
        self::assertStringNotContainsString('Database Cluster DELETE execution is not supported', $text->getDisplay());
        self::assertStringContainsString('environment_attachment', $text->getDisplay());

        $json = $this->tester(self::validBlueprint(), cloud: new PlanDatabaseCloudClient(), state: $state);
        self::assertSame(ExitCode::SUCCESS->value, $json->execute(['--json' => true]));
        $decoded = json_decode($json->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['actions']);
        $database = null;
        $databaseCluster = null;
        foreach ($decoded['actions'] as $action) {
            if (is_array($action) && ($action['resource'] ?? null) === 'database.primary.application') {
                $database = $action;
            }
            if (is_array($action) && ($action['resource'] ?? null) === 'database_cluster.primary') {
                $databaseCluster = $action;
            }
        }
        self::assertIsArray($database);
        self::assertSame('blocked', $database['destructive_readiness']);
        self::assertIsString($database['reason']);
        self::assertStringContainsString('guarded deletion is blocked', $database['reason']);
        self::assertStringContainsString($database['reason'], $text->getDisplay());
        self::assertStringNotContainsString('not supported', $database['reason']);
        self::assertSame(['environment_attachment'], $database['blocking_dependencies']);
        self::assertSame([], $database['missing_dependency_relationships']);
        self::assertIsArray($databaseCluster);
        self::assertIsString($databaseCluster['reason']);
        self::assertStringContainsString('guarded deletion is blocked', $databaseCluster['reason']);

        foreach ([$text->getDisplay(), $json->getDisplay()] as $output) {
            self::assertStringNotContainsString('cluster-secret-id', $output);
            self::assertStringNotContainsString('database-secret-id', $output);
            self::assertStringNotContainsString('environment-secret-id', $output);
        }
    }

    public function testSafeAndUnknownLogicalDatabaseDeletePresentationMatchesJsonReason(): void
    {
        $cluster = new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary');
        $state = StateDocument::empty()->withOrganization('acme')
            ->withResource(new StateResource($cluster, ResourceType::DATABASE_CLUSTER, 'cluster-secret-id'))
            ->withResource(new StateResource(
                new ResourceAddress(ResourceType::DATABASE, 'primary.application'),
                ResourceType::DATABASE,
                'database-secret-id',
                $cluster,
            ));

        foreach ([
            'safe' => new CloudDatabase(
                'database-secret-id', 'cluster-secret-id', 'application', 'cluster-secret-id', [], true,
            ),
            'unknown' => new CloudDatabase('database-secret-id', 'cluster-secret-id', 'application'),
        ] as $readiness => $detail) {
            $cloud = new PlanDatabaseCloudClient($detail);
            $text = $this->tester(self::validBlueprint(), cloud: $cloud, state: $state);
            self::assertSame(ExitCode::SUCCESS->value, $text->execute([]));
            self::assertStringContainsString('Destructive readiness: ' . $readiness, $text->getDisplay());
            self::assertStringNotContainsString('Database DELETE execution is not supported', $text->getDisplay());

            $json = $this->tester(self::validBlueprint(), cloud: $cloud, state: $state);
            self::assertSame(ExitCode::SUCCESS->value, $json->execute(['--json' => true]));
            $decoded = json_decode($json->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            self::assertIsArray($decoded['actions']);
            $database = null;
            foreach ($decoded['actions'] as $action) {
                if (is_array($action) && ($action['resource'] ?? null) === 'database.primary.application') {
                    $database = $action;
                    break;
                }
            }
            self::assertIsArray($database);
            self::assertSame($readiness, $database['destructive_readiness']);
            self::assertSame(
                $readiness === 'safe' ? 'supported' : 'blocked',
                $database['reconciliation'],
            );
            self::assertIsString($database['reason']);
            self::assertStringContainsString($database['reason'], $text->getDisplay());
            self::assertStringNotContainsString('not supported', $database['reason']);
        }
    }

    public function testClusterSnapshotDiagnosticsAreSafeAndConsistentInTextAndJson(): void
    {
        $cluster = new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary');
        $state = StateDocument::empty()->withOrganization('acme')->withResource(
            new StateResource($cluster, ResourceType::DATABASE_CLUSTER, 'cluster-secret-id'),
        );
        $cloud = new PlanDatabaseCloudClient(snapshots: [new CloudDatabaseSnapshot(
            'snapshot-secret-id',
            'cluster-secret-id',
            DatabaseSnapshotType::MANUAL,
            DatabaseSnapshotStatus::AVAILABLE,
            false,
        )]);

        $text = $this->tester(self::validBlueprint(), cloud: $cloud, state: $state);
        self::assertSame(ExitCode::SUCCESS->value, $text->execute([]));
        self::assertStringContainsString('database_snapshot', $text->getDisplay());
        self::assertStringContainsString('Discovered snapshots: 1 (1 manual, 0 scheduled).', $text->getDisplay());
        self::assertStringNotContainsString('Database Cluster DELETE execution is not supported', $text->getDisplay());

        $json = $this->tester(self::validBlueprint(), cloud: $cloud, state: $state);
        self::assertSame(ExitCode::SUCCESS->value, $json->execute(['--json' => true]));
        $decoded = json_decode($json->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['actions']);
        $actions = $decoded['actions'];
        $action = $actions[0];
        self::assertIsArray($action);
        self::assertSame('blocked', $action['destructive_readiness']);
        self::assertSame('blocked', $action['reconciliation']);
        self::assertIsArray($action['blocking_dependencies']);
        self::assertContains('database_snapshot', $action['blocking_dependencies']);
        self::assertTrue($action['snapshot_discovery_complete']);
        self::assertSame(1, $action['snapshot_count']);
        self::assertSame(1, $action['manual_snapshot_count']);
        self::assertSame(0, $action['scheduled_snapshot_count']);
        self::assertTrue($action['recovery_evidence_complete']);
        self::assertSame('eligible', $action['cluster_lifecycle_readiness']);
        foreach ([$text->getDisplay(), $json->getDisplay()] as $output) {
            self::assertStringNotContainsString('snapshot-secret-id', $output);
            self::assertStringNotContainsString('cluster-secret-id', $output);
        }
    }

    private function tester(
        string $blueprint,
        ?CloudApiToken $token = new CloudApiToken('super-secret-token'),
        LaravelCloudClient $cloud = new PlanCommandCloudClient(),
        bool $fileExists = true,
        ?StateDocument $state = null,
    ): CommandTester {
        return new CommandTester(new PlanCommand(
            new PlanFileReader($blueprint, $fileExists),
            new BlueprintLoader(new SymfonyYamlDecoder(), new BlueprintValidator(), new BlueprintNormalizer()),
            new PlanTokenProvider($token),
            new PlanClientFactory($cloud),
            new CreatePlan(new VariableValueResolver(new PlanEnvironmentValueProvider())),
            new PlanCommandStateStore($state ?? StateDocument::empty()),
        ));
    }

    private static function managedState(): StateDocument
    {
        $application = new ResourceAddress(ResourceType::APPLICATION, 'API');

        return StateDocument::empty()->withOrganization('acme')
            ->withResource(new StateResource($application, ResourceType::APPLICATION, 'app-1'))
            ->withResource(new StateResource(
                new ResourceAddress(ResourceType::ENVIRONMENT, 'production'),
                ResourceType::ENVIRONMENT,
                'env-1',
                $application,
            ));
    }

    private static function assertJsonError(CommandTester $tester, string $message): void
    {
        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('error', $decoded['status']);
        self::assertIsString($decoded['message']);
        self::assertStringContainsString($message, $decoded['message']);
    }

    private static function validBlueprint(string $applicationName = 'API'): string
    {
        return <<<YAML
version: 1
organization: acme
application:
  name: {$applicationName}
  region: eu-central-1
  source:
    provider: github
    repository: acme/api
environments:
  production:
    branch: main
YAML;
    }

    private static function addressCollisionBlueprint(): string
    {
        return <<<'YAML'
version: 1
organization: acme
application:
  name: API
  region: eu-central-1
  source:
    provider: github
    repository: acme/api
environments:
  foo.bar:
    branch: main
    variables:
      baz:
        value: one
  foo:
    branch: main
    variables:
      bar.baz:
        value: two
YAML;
    }

    private static function blueprintWithSecrets(): string
    {
        return <<<'YAML'
version: 1
organization: acme
application:
  name: new-api
  region: eu-central-1
  source:
    provider: github
    repository: acme/api
environments:
  production:
    branch: main
    variables:
      APP_LITERAL:
        value: literal-super-secret
        sensitive: true
      APP_KEY:
        from_env: LOCAL_APP_KEY
        sensitive: true
YAML;
    }

    private static function blueprintWithUpdate(): string
    {
        return <<<'YAML'
version: 1
organization: acme
application:
  name: API
  region: eu-central-1
  source:
    provider: github
    repository: acme/api
environments:
  production:
    branch: main
    variables:
      APP_KEY:
        value: desired-variable-secret
        sensitive: true
YAML;
    }

    private static function databaseBlueprint(): string
    {
        return <<<'YAML'
version: 1
organization: acme
application:
  name: API
  region: eu-central-1
  source:
    provider: github
    repository: acme/api
database_clusters:
  primary:
    type: laravel_mysql_8
    region: eu-central-1
    config:
      size: db-flex.m-1vcpu-512mb
      storage: 5
      retention_days: 1
      uses_scheduled_snapshots: false
      is_public: false
    databases:
      application: {}
environments:
  production:
    branch: main
    database: primary.application
YAML;
    }
}

final class PlanCommandStateStore implements StateStore
{
    public function __construct(private readonly StateDocument $state)
    {
    }

    public function load(): StateDocument
    {
        return $this->state;
    }

    public function save(StateDocument $state): StateDocument
    {
        throw new LogicException('Plan must not save state.');
    }

    public function begin(): StateTransaction
    {
        throw new LogicException('Plan must not begin a state transaction.');
    }
}

final readonly class PlanFileReader implements FileReader
{
    public function __construct(private string $contents, private bool $exists = true)
    {
    }

    public function exists(string $path): bool
    {
        return $this->exists;
    }

    public function read(string $path): string
    {
        return $this->contents;
    }
}

final readonly class PlanTokenProvider implements CloudTokenProvider
{
    public function __construct(private ?CloudApiToken $token)
    {
    }

    public function token(): ?CloudApiToken
    {
        return $this->token;
    }
}

final readonly class PlanClientFactory implements LaravelCloudClientFactory
{
    public function __construct(private LaravelCloudClient $client)
    {
    }

    public function create(CloudApiToken $token): LaravelCloudClient
    {
        return $this->client;
    }
}

class PlanCommandCloudClient implements LaravelCloudClient
{
    public function updateEnvironment(string $environmentId, \LaravelCloudBlueprint\Cloud\DTO\UpdateEnvironmentRequest $request): \LaravelCloudBlueprint\Cloud\DTO\UpdatedCloudEnvironment
    {
        throw new LogicException('Plan fake must remain read-only.');
    }
    public function organization(): CloudOrganization
    {
        return new CloudOrganization('org-1', 'Acme', 'acme');
    }

    public function applications(): array
    {
        return [new CloudApplication('app-1', 'API', 'api', 'eu-central-1', 'acme/api')];
    }

    public function environments(string $applicationId): array
    {
        return [new CloudEnvironment('env-1', $applicationId, 'production', 'main')];
    }

    public function environment(string $environmentId): CloudEnvironmentDetails
    {
        return new CloudEnvironmentDetails($environmentId, 'production', null);
    }

    public function createApplication(CreateApplicationRequest $request): CloudApplication
    {
        throw new LogicException('Plan fake must remain read-only.');
    }

    public function createEnvironment(string $applicationId, CreateEnvironmentRequest $request): CloudEnvironment
    {
        throw new LogicException('Plan fake must remain read-only.');
    }

    public function setEnvironmentVariables(string $environmentId, SetEnvironmentVariablesRequest $request): void
    {
        throw new LogicException('Plan fake must remain read-only.');
    }
}

final class PlanDependencyCloudClient extends PlanCommandCloudClient
{
    public function environments(string $applicationId): array
    {
        return [
            new CloudEnvironment('env-1', $applicationId, 'production', 'main'),
            new CloudEnvironment(
                'env-preview',
                $applicationId,
                'preview',
                'feature',
                'database-internal-id',
                new EnvironmentDependencies(
                    'database-internal-id', null, null, 1, 0, 0, 0, 0, false, false, true,
                ),
            ),
        ];
    }
}

final class PlanInstanceCloudClient extends PlanCommandCloudClient
{
    public function environments(string $applicationId): array
    {
        $dependencies = new EnvironmentDependencies(
            null, null, null, 0, 1, 0, 0, 0, false, false, true,
        );

        return [
            new CloudEnvironment('env-1', $applicationId, 'production', 'main'),
            new CloudEnvironment('env-preview', $applicationId, 'preview', 'feature', dependencies: $dependencies),
        ];
    }
}

final readonly class PlanEnvironmentValueProvider implements EnvironmentValueProvider
{
    public function value(string $name): string
    {
        return 'resolved-super-secret';
    }
}

final class PlanThrowingCloudClient extends PlanCommandCloudClient
{
    public function organization(): CloudOrganization
    {
        throw new CloudApiException('Laravel Cloud is unavailable.', 'GET', '/meta/organization', 500);
    }
}

final class PlanUpdateCloudClient extends PlanCommandCloudClient
{
    public function applications(): array
    {
        return [new CloudApplication('app-1', 'API', 'api', 'eu-central-1', 'acme/old-api')];
    }

    public function environments(string $applicationId): array
    {
        return [new CloudEnvironment('env-1', $applicationId, 'production', 'old-branch')];
    }

    public function environment(string $environmentId): CloudEnvironmentDetails
    {
        return new CloudEnvironmentDetails($environmentId, 'production', new CloudEnvironmentVariableCollection(
            new CloudEnvironmentVariable('APP_KEY', 'remote-variable-secret'),
        ));
    }
}

final class PlanInvalidUtf8CloudClient extends PlanCommandCloudClient
{
    public function environments(string $applicationId): array
    {
        return [new CloudEnvironment('env-1', $applicationId, 'production', "invalid-\xB1")];
    }
}

final class PlanDatabaseCloudClient extends PlanCommandCloudClient implements LaravelCloudDatabaseLifecycleClient
{
    /**
     * @param list<CloudDatabaseSnapshot> $snapshots
     * @param list<CloudDatabase> $databases
     */
    public function __construct(
        private readonly ?CloudDatabase $databaseDetail = null,
        private readonly array $snapshots = [],
        private readonly array $databases = [],
    )
    {
    }

    public function environments(string $applicationId): array
    {
        return [new CloudEnvironment('env-1', $applicationId, 'production', 'main', 'database-secret-id')];
    }

    public function databaseClusters(): array
    {
        $databaseIds = $this->databases === []
            ? ['database-secret-id']
            : array_map(static fn (CloudDatabase $database): string => $database->id, $this->databases);
        return [new CloudDatabaseCluster(
            'cluster-secret-id',
            'primary',
            'laravel_mysql_8',
            'available',
            'eu-central-1',
            new CloudLaravelMySqlConfiguration('db-flex.m-1vcpu-512mb', 5, 1, false, false),
            $databaseIds,
            true,
        )];
    }

    public function databaseCluster(string $clusterId): CloudDatabaseCluster
    {
        return $this->databaseClusters()[0];
    }

    public function databases(string $clusterId): array
    {
        return $this->databases !== []
            ? $this->databases
            : [new CloudDatabase('database-secret-id', $clusterId, 'application')];
    }

    public function databaseSnapshots(string $clusterId): array
    {
        return $this->snapshots;
    }

    public function database(string $clusterId, string $databaseId): CloudDatabase
    {
        return $this->databaseDetail ?? new CloudDatabase(
            $databaseId,
            $clusterId,
            'application',
            $clusterId,
            ['environment-secret-id'],
            true,
        );
    }

    public function databaseWithDestructiveRelationships(string $clusterId, string $databaseId): CloudDatabase
    {
        return $this->database($clusterId, $databaseId);
    }
}
