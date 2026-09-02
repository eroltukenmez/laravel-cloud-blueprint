<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Console\Command;

use LaravelCloudBlueprint\Application\BlueprintLoader;
use LaravelCloudBlueprint\Application\File\FileReader;
use LaravelCloudBlueprint\Apply\CreateOnlyApply;
use LaravelCloudBlueprint\Blueprint\Normalization\BlueprintNormalizer;
use LaravelCloudBlueprint\Blueprint\Validation\BlueprintValidator;
use LaravelCloudBlueprint\Cloud\CloudApiToken;
use LaravelCloudBlueprint\Cloud\Contract\CloudTokenProvider;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudClient;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudClientFactory;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudEnvironmentMutationClient;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudLogicalDatabaseDeletionClient;
use LaravelCloudBlueprint\Cloud\DTO\CloudApplication;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabase;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabaseCluster;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironment;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironmentDetails;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironmentVariable;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironmentVariableCollection;
use LaravelCloudBlueprint\Cloud\DTO\CloudOrganization;
use LaravelCloudBlueprint\Cloud\DTO\CloudLaravelMySqlConfiguration;
use LaravelCloudBlueprint\Cloud\DTO\CreateApplicationRequest;
use LaravelCloudBlueprint\Cloud\DTO\CreateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\DTO\EnvironmentDependencies;
use LaravelCloudBlueprint\Cloud\DTO\SetEnvironmentVariablesRequest;
use LaravelCloudBlueprint\Cloud\Exception\CloudValidationException;
use LaravelCloudBlueprint\Cloud\Exception\CloudResourceNotFoundException;
use LaravelCloudBlueprint\Console\Command\ApplyCommand;
use LaravelCloudBlueprint\Console\ExitCode;
use LaravelCloudBlueprint\Infrastructure\Yaml\SymfonyYamlDecoder;
use LaravelCloudBlueprint\Planning\CreatePlan;
use LaravelCloudBlueprint\Planning\Contract\EnvironmentValueProvider;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\Planning\VariableValueResolver;
use LaravelCloudBlueprint\State\Contract\StateStore;
use LaravelCloudBlueprint\State\Contract\StateTransaction;
use LaravelCloudBlueprint\State\Exception\StateCorruptedException;
use LaravelCloudBlueprint\State\Exception\StateStorageException;
use LaravelCloudBlueprint\State\StateDocument;
use LaravelCloudBlueprint\State\StateResource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class ApplyCommandTest extends TestCase
{
    public function testInteractiveDeclinePerformsNoMutation(): void
    {
        [$tester, $cloud, $state] = $this->tester();
        $tester->setInputs(['no']);

        self::assertSame(ExitCode::SUCCESS->value, $tester->execute([]));
        self::assertSame(0, $cloud->mutationCount);
        self::assertSame(0, $state->beginCount);
        self::assertStringContainsString('Apply cancelled. No resources were modified.', $tester->getDisplay());
    }

    public function testAutoApproveExecutesWithoutPromptAndRendersDeterministically(): void
    {
        [$tester, $cloud, $state] = $this->tester();

        self::assertSame(ExitCode::SUCCESS->value, $tester->execute(['--auto-approve' => true]));
        self::assertSame(2, $cloud->mutationCount);
        self::assertSame(1, $state->beginCount);
        self::assertSame(1, $state->releaseCount);
        self::assertStringNotContainsString('Apply these changes?', $tester->getDisplay());
        self::assertSame(
            "Laravel Cloud Blueprint Apply\n\n"
            . "+ application.my-api\n  Application does not exist.\n"
            . "+ environment.production\n  Environment does not exist because the application will be created.\n\n"
            . "application.my-api: created\n"
            . "environment.production: created\n"
            . "Apply success: 2 created, 0 updated, 0 unchanged.\n",
            $tester->getDisplay(),
        );
    }

    public function testNonInteractiveWithoutAutoApproveRefusesMutation(): void
    {
        [$tester, $cloud, $state] = $this->tester();

        self::assertSame(ExitCode::GENERAL_ERROR->value, $tester->execute(['--non-interactive' => true]));
        self::assertSame(0, $cloud->mutationCount);
        self::assertSame(0, $state->beginCount);
        self::assertStringContainsString('requires --auto-approve', $tester->getDisplay());
    }

    public function testNonInteractiveWithAutoApproveExecutes(): void
    {
        [$tester, $cloud, $state] = $this->tester();

        self::assertSame(ExitCode::SUCCESS->value, $tester->execute([
            '--non-interactive' => true,
            '--auto-approve' => true,
        ]));
        self::assertSame(2, $cloud->mutationCount);
        self::assertSame(1, $state->releaseCount);
    }

    public function testJsonOutputContainsOnlyValidStructuredDataAndNoToken(): void
    {
        [$tester, $cloud] = $this->tester();

        self::assertSame(ExitCode::SUCCESS->value, $tester->execute([
            '--json' => true,
            '--auto-approve' => true,
        ]));
        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('success', $decoded['status']);
        self::assertSame(['created' => 2, 'updated' => 0, 'unchanged' => 0], $decoded['summary']);
        self::assertIsArray($decoded['resources']);
        self::assertIsArray($decoded['resources'][0]);
        self::assertSame('application.my-api', $decoded['resources'][0]['resource']);
        self::assertSame('created', $decoded['resources'][0]['operation']);
        self::assertStringNotContainsString('super-secret-token', $tester->getDisplay());
        self::assertSame(2, $cloud->mutationCount);
    }

    public function testNoChangeDoesNotPromptMutateOrManageExistingResources(): void
    {
        $cloud = ApplyCommandCloudClient::matching();
        [$tester, , $state] = $this->tester($cloud);

        self::assertSame(ExitCode::SUCCESS->value, $tester->execute([]));
        self::assertSame(0, $cloud->mutationCount);
        self::assertSame(0, $state->beginCount);
        self::assertSame([], $state->state->resources());
        self::assertStringContainsString("No changes.\n\nLaravel Cloud infrastructure matches the blueprint.", $tester->getDisplay());
    }

    public function testVariableApplyTextAndJsonExposeOutcomesButNeverValuesOrReferences(): void
    {
        [$text, $textCloud] = $this->tester(blueprint: self::blueprintWithVariables());
        self::assertSame(ExitCode::SUCCESS->value, $text->execute(['--auto-approve' => true]));
        self::assertSame(3, $textCloud->mutationCount);
        self::assertStringContainsString('variable.production.APP_KEY: created', $text->getDisplay());

        [$json, $jsonCloud] = $this->tester(blueprint: self::blueprintWithVariables());
        self::assertSame(ExitCode::SUCCESS->value, $json->execute([
            '--auto-approve' => true,
            '--json' => true,
        ]));
        self::assertSame(3, $jsonCloud->mutationCount);
        $decoded = json_decode($json->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['summary']);
        self::assertSame(4, $decoded['summary']['created']);

        foreach ([$text->getDisplay(), $json->getDisplay()] as $output) {
            self::assertStringNotContainsString('literal-secret-value', $output);
            self::assertStringNotContainsString('resolved-apply-secret', $output);
            self::assertStringNotContainsString('LOCAL_APP_KEY', $output);
        }
    }

    public function testVariableUpdateRendersUpdatedInTextAndJsonWithoutValues(): void
    {
        [$text, $textCloud] = $this->tester(
            ApplyCommandCloudClient::withVariableUpdate(),
            self::blueprintWithVariableUpdate(),
        );
        self::assertSame(ExitCode::SUCCESS->value, $text->execute(['--auto-approve' => true]));
        self::assertSame(1, $textCloud->mutationCount);
        self::assertStringContainsString('~ variable.production.APP_ENV', $text->getDisplay());
        self::assertStringContainsString('variable.production.APP_ENV: updated', $text->getDisplay());
        self::assertStringContainsString('Apply success: 0 created, 1 updated, 2 unchanged.', $text->getDisplay());

        [$json] = $this->tester(
            ApplyCommandCloudClient::withVariableUpdate(),
            self::blueprintWithVariableUpdate(),
        );
        self::assertSame(ExitCode::SUCCESS->value, $json->execute(['--json' => true, '--auto-approve' => true]));
        $decoded = json_decode($json->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['summary']);
        self::assertIsArray($decoded['resources']);
        self::assertIsArray($decoded['resources'][2]);
        self::assertSame(1, $decoded['summary']['updated']);
        self::assertSame('updated', $decoded['resources'][2]['operation']);

        foreach ([$text->getDisplay(), $json->getDisplay()] as $output) {
            self::assertStringNotContainsString('desired-update-secret', $output);
            self::assertStringNotContainsString('remote-update-secret', $output);
        }
    }

    public function testEnvironmentBranchUpdateRendersSafeDiffAndUpdatedOutcome(): void
    {
        [$text, $textCloud, $state] = $this->tester(
            ApplyCommandCloudClient::withEnvironmentUpdate(),
            stateDocument: self::managedState(),
        );

        self::assertSame(ExitCode::SUCCESS->value, $text->execute(['--auto-approve' => true]));
        self::assertSame(1, $textCloud->mutationCount);
        self::assertSame(0, $state->state->serial);
        self::assertStringContainsString('~ environment.production', $text->getDisplay());
        self::assertStringContainsString('branch: develop → main', $text->getDisplay());
        self::assertStringContainsString('environment.production: updated', $text->getDisplay());

        [$json] = $this->tester(
            ApplyCommandCloudClient::withEnvironmentUpdate(),
            stateDocument: self::managedState(),
        );
        self::assertSame(ExitCode::SUCCESS->value, $json->execute(['--json' => true, '--auto-approve' => true]));
        $decoded = json_decode($json->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['resources']);
        self::assertIsArray($decoded['resources'][1]);
        self::assertSame('environment.production', $decoded['resources'][1]['resource']);
        self::assertSame('updated', $decoded['resources'][1]['operation']);
    }

    public function testUnmanagedEnvironmentBranchDifferenceIsNeverPatched(): void
    {
        [$tester, $cloud, $state] = $this->tester(ApplyCommandCloudClient::withEnvironmentUpdate());

        self::assertSame(ExitCode::GENERAL_ERROR->value, $tester->execute(['--auto-approve' => true]));
        self::assertSame(0, $cloud->mutationCount);
        self::assertSame(0, $state->beginCount);
        self::assertStringContainsString('unsupported changes', $tester->getDisplay());
    }

    public function testOwnedOnlyLifecycleActionBlocksApplicationCreateBeforeMutationOrStateTransaction(): void
    {
        $oldApplication = new ResourceAddress(ResourceType::APPLICATION, 'old-api');
        $stateDocument = StateDocument::empty()->withOrganization('acme')->withResource(
            new StateResource($oldApplication, ResourceType::APPLICATION, 'app-old'),
        );
        [$tester, $cloud, $state] = $this->tester(stateDocument: $stateDocument);

        self::assertSame(ExitCode::GENERAL_ERROR->value, $tester->execute(['--auto-approve' => true]));
        self::assertSame(0, $cloud->mutationCount);
        self::assertSame(0, $state->beginCount);
        self::assertSame(0, $state->state->serial);
        self::assertSame('app-old', $state->state->get($oldApplication)->remoteId);
        self::assertStringContainsString('destructive execution is not enabled', $tester->getDisplay());
        self::assertStringNotContainsString('Apply these changes?', $tester->getDisplay());
    }

    public function testOwnedOnlyLifecycleActionBlocksEnvironmentUpdateBeforeMutation(): void
    {
        $application = new ResourceAddress(ResourceType::APPLICATION, 'my-api');
        $preview = new ResourceAddress(ResourceType::ENVIRONMENT, 'preview');
        $stateDocument = self::managedState()->withResource(
            new StateResource($preview, ResourceType::ENVIRONMENT, 'env-preview', $application),
        );
        [$tester, $cloud, $state] = $this->tester(
            ApplyCommandCloudClient::withEnvironmentUpdate(),
            stateDocument: $stateDocument,
        );

        self::assertSame(ExitCode::GENERAL_ERROR->value, $tester->execute(['--auto-approve' => true]));
        self::assertSame(0, $cloud->mutationCount);
        self::assertSame(0, $state->beginCount);
        self::assertSame(0, $state->state->serial);
    }

    public function testEnvironmentDeleteRequiresExplicitApprovalInHumanJsonAndNonInteractiveModes(): void
    {
        $application = new ResourceAddress(ResourceType::APPLICATION, 'my-api');
        $preview = new ResourceAddress(ResourceType::ENVIRONMENT, 'preview');
        $stateDocument = self::managedState()->withResource(
            new StateResource($preview, ResourceType::ENVIRONMENT, 'env-preview', $application),
        );

        [$human, $humanCloud, $humanState] = $this->tester(
            ApplyCommandCloudClient::withSafePreview(),
            stateDocument: $stateDocument,
        );
        $human->setInputs(['no']);
        self::assertSame(ExitCode::SUCCESS->value, $human->execute([]));
        self::assertSame(0, $humanCloud->mutationCount);
        self::assertSame(0, $humanState->beginCount);
        self::assertStringContainsString('permanently deletes a Laravel Cloud Environment', $human->getDisplay());
        self::assertStringContainsString('state:unmanage', $human->getDisplay());

        foreach ([['--json' => true], ['--non-interactive' => true]] as $options) {
            [$tester, $cloud, $state] = $this->tester(
                ApplyCommandCloudClient::withSafePreview(),
                stateDocument: $stateDocument,
            );
            self::assertSame(ExitCode::GENERAL_ERROR->value, $tester->execute($options));
            self::assertSame(0, $cloud->mutationCount);
            self::assertSame(0, $state->beginCount);
            self::assertStringContainsString('requires --auto-approve', $tester->getDisplay());
            if (isset($options['--json'])) {
                self::assertIsArray(json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
            }
        }
    }

    public function testAutoApproveAllowsEnvironmentDeleteWithOrdinaryInstance(): void
    {
        $application = new ResourceAddress(ResourceType::APPLICATION, 'my-api');
        $preview = new ResourceAddress(ResourceType::ENVIRONMENT, 'preview');
        $stateDocument = self::managedState()->withResource(
            new StateResource($preview, ResourceType::ENVIRONMENT, 'env-preview', $application),
        );
        [$tester, $cloud, $state] = $this->tester(
            ApplyCommandEnvironmentDeleteClient::withInstancePreview(),
            stateDocument: $stateDocument,
        );

        self::assertSame(ExitCode::SUCCESS->value, $tester->execute(['--auto-approve' => true]));
        self::assertInstanceOf(ApplyCommandEnvironmentDeleteClient::class, $cloud);
        self::assertSame(['env-preview'], $cloud->deletedIds);
        self::assertSame(1, $state->beginCount);
        self::assertSame(1, $state->releaseCount);
    }

    public function testLogicalDatabaseDeleteRequiresExplicitApprovalAndRendersStableOutcomes(): void
    {
        foreach ([['--json' => true], ['--non-interactive' => true]] as $options) {
            $cloud = ApplyCommandLogicalDatabaseDeleteClient::matching();
            [$tester, , $state] = $this->tester($cloud, self::databaseDeletionBlueprint(), self::databaseDeletionState());
            self::assertSame(ExitCode::GENERAL_ERROR->value, $tester->execute($options));
            self::assertSame(0, $cloud->mutationCount);
            self::assertSame(0, $state->beginCount);
            self::assertStringContainsString('requires --auto-approve', $tester->getDisplay());
        }

        $declinedCloud = ApplyCommandLogicalDatabaseDeleteClient::matching();
        [$declined, , $declinedState] = $this->tester(
            $declinedCloud,
            self::databaseDeletionBlueprint(),
            self::databaseDeletionState(),
        );
        $declined->setInputs(['no']);
        self::assertSame(ExitCode::SUCCESS->value, $declined->execute([]));
        self::assertSame(0, $declinedCloud->mutationCount);
        self::assertSame(0, $declinedState->beginCount);
        self::assertStringContainsString('permanently deletes a Laravel Cloud logical Database', $declined->getDisplay());
        self::assertStringContainsString('state:unmanage', $declined->getDisplay());

        $approvedCloud = ApplyCommandLogicalDatabaseDeleteClient::matching();
        [$approved, , $approvedState] = $this->tester(
            $approvedCloud,
            self::databaseDeletionBlueprint(),
            self::databaseDeletionState(),
        );
        self::assertSame(ExitCode::SUCCESS->value, $approved->execute([
            '--json' => true,
            '--auto-approve' => true,
        ]));
        $decoded = json_decode($approved->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('success', $decoded['status']);
        self::assertIsArray($decoded['resources']);
        $resource = $decoded['resources'][0];
        self::assertIsArray($resource);
        self::assertSame('database.primary.application', $resource['resource']);
        self::assertSame('delete_confirmed', $resource['outcome']);
        self::assertSame(1, $approvedCloud->mutationCount);
        self::assertNull($approvedState->state->find(new ResourceAddress(ResourceType::DATABASE, 'primary.application')));
        self::assertStringNotContainsString('database-secret-id', $approved->getDisplay());
    }

    public function testOwnedOnlyLifecycleActionBlocksVariableUpdateWithoutLeakingValues(): void
    {
        $application = new ResourceAddress(ResourceType::APPLICATION, 'my-api');
        $preview = new ResourceAddress(ResourceType::ENVIRONMENT, 'preview');
        $stateDocument = self::managedState()->withResource(
            new StateResource($preview, ResourceType::ENVIRONMENT, 'env-preview', $application),
        );
        [$tester, $cloud, $state] = $this->tester(
            ApplyCommandCloudClient::withVariableUpdate(),
            self::blueprintWithVariableUpdate(),
            $stateDocument,
        );

        self::assertSame(ExitCode::GENERAL_ERROR->value, $tester->execute(['--auto-approve' => true]));
        self::assertSame(0, $cloud->mutationCount);
        self::assertSame(0, $state->beginCount);
        self::assertSame(0, $state->state->serial);
        self::assertStringNotContainsString('desired-update-secret', $tester->getDisplay());
        self::assertStringNotContainsString('remote-update-secret', $tester->getDisplay());
    }

    public function testInitialCorruptedStateLoadRendersControlledHumanError(): void
    {
        [$tester, $cloud, $state] = $this->tester(
            stateLoadFailure: new StateCorruptedException('Local state contains invalid JSON.'),
        );

        self::assertSame(ExitCode::GENERAL_ERROR->value, $tester->execute([]));
        self::assertStringContainsString('Local state contains invalid JSON.', $tester->getDisplay());
        self::assertSame(0, $cloud->mutationCount);
        self::assertSame(0, $state->beginCount);
    }

    public function testInitialCorruptedStateLoadRendersValidJsonError(): void
    {
        [$tester, $cloud, $state] = $this->tester(
            stateLoadFailure: new StateCorruptedException('Local state contains invalid JSON.'),
        );

        self::assertSame(ExitCode::GENERAL_ERROR->value, $tester->execute(['--json' => true]));
        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('error', $decoded['status']);
        self::assertIsString($decoded['message']);
        self::assertStringContainsString('invalid JSON', $decoded['message']);
        self::assertStringNotContainsString('<error>', $tester->getDisplay());
        self::assertStringNotContainsString('super-secret-token', $tester->getDisplay());
        self::assertSame(0, $cloud->mutationCount);
        self::assertSame(0, $state->beginCount);
    }

    public function testInitialStateStorageFailureRendersControlledError(): void
    {
        [$tester, $cloud, $state] = $this->tester(
            stateLoadFailure: new StateStorageException('Unable to read local state.'),
        );

        self::assertSame(ExitCode::GENERAL_ERROR->value, $tester->execute(['--json' => true]));
        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('error', $decoded['status']);
        self::assertSame(0, $cloud->mutationCount);
        self::assertSame(0, $state->beginCount);
    }

    public function testCloudValidationErrorsRenderSafelyInTextAndJson(): void
    {
        $validation = new CloudValidationException(
            'The given data was invalid.',
            [
                'variables.0.key' => ['The variable key is already present.'],
                'variables.1.key' => ['The variable key format is invalid.'],
            ],
            'POST',
            '/environments/env-created/variables',
            422,
            'request-422',
        );

        [$text] = $this->tester(
            ApplyCommandCloudClient::withVariableValidationFailure($validation),
            self::blueprintWithVariables(),
        );
        self::assertSame(ExitCode::GENERAL_ERROR->value, $text->execute(['--auto-approve' => true]));
        self::assertStringContainsString('Laravel Cloud rejected the request.', $text->getDisplay());
        self::assertStringContainsString('The given data was invalid.', $text->getDisplay());
        self::assertStringContainsString('variables.0.key:', $text->getDisplay());
        self::assertStringContainsString('The variable key is already present.', $text->getDisplay());

        [$json] = $this->tester(
            ApplyCommandCloudClient::withVariableValidationFailure($validation),
            self::blueprintWithVariables(),
        );
        self::assertSame(ExitCode::GENERAL_ERROR->value, $json->execute([
            '--auto-approve' => true,
            '--json' => true,
        ]));
        $decoded = json_decode($json->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['resources']);
        self::assertIsArray($decoded['resources'][2]);
        self::assertIsArray($decoded['resources'][2]['validation']);
        self::assertIsArray($decoded['resources'][2]['validation']['errors']);
        self::assertSame('The given data was invalid.', $decoded['resources'][2]['validation']['message']);
        self::assertSame(
            ['The variable key is already present.'],
            $decoded['resources'][2]['validation']['errors']['variables.0.key'],
        );

        foreach ([$text->getDisplay(), $json->getDisplay()] as $rendered) {
            self::assertStringNotContainsString('super-secret-token', $rendered);
            self::assertStringNotContainsString('literal-secret-value', $rendered);
            self::assertStringNotContainsString('resolved-apply-secret', $rendered);
        }
    }

    public function testJsonEncodingFailureProducesSafeValidJson(): void
    {
        $validation = new CloudValidationException(
            "invalid-\xB1",
            [],
            'POST',
            '/environments/env-created/variables',
            422,
        );
        [$tester] = $this->tester(
            ApplyCommandCloudClient::withVariableValidationFailure($validation),
            self::blueprintWithVariables(),
        );

        self::assertSame(ExitCode::GENERAL_ERROR->value, $tester->execute([
            '--auto-approve' => true,
            '--json' => true,
        ]));
        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('error', $decoded['status']);
        self::assertSame('Unable to encode command output as JSON.', $decoded['message']);
        self::assertStringNotContainsString('<error>', $tester->getDisplay());
        self::assertStringNotContainsString('literal-secret-value', $tester->getDisplay());
        self::assertStringNotContainsString('super-secret-token', $tester->getDisplay());
    }

    /** @return array{CommandTester, ApplyCommandCloudClient, ApplyCommandStateStore} */
    private function tester(
        ?ApplyCommandCloudClient $cloud = null,
        ?string $blueprint = null,
        ?StateDocument $stateDocument = null,
        StateCorruptedException|StateStorageException|null $stateLoadFailure = null,
    ): array
    {
        $cloud ??= ApplyCommandCloudClient::empty();
        $state = new ApplyCommandStateStore($stateLoadFailure);
        if ($stateDocument !== null) {
            $state->state = $stateDocument;
        }
        $values = new VariableValueResolver(new ApplyCommandEnvironmentValueProvider());
        $command = new ApplyCommand(
            new ApplyCommandFileReader($blueprint ?? self::blueprint()),
            new BlueprintLoader(new SymfonyYamlDecoder(), new BlueprintValidator(), new BlueprintNormalizer()),
            new ApplyCommandTokenProvider(new CloudApiToken('super-secret-token')),
            new ApplyCommandClientFactory($cloud),
            new CreatePlan($values),
            new CreateOnlyApply($values),
            $state,
        );

        $application = new Application();
        $application->add($command);

        return [new CommandTester($application->find('apply')), $cloud, $state];
    }

    private static function managedState(): StateDocument
    {
        $application = new ResourceAddress(ResourceType::APPLICATION, 'my-api');

        return StateDocument::empty()->withOrganization('acme')
            ->withResource(new StateResource($application, ResourceType::APPLICATION, 'app-existing'))
            ->withResource(new StateResource(
                new ResourceAddress(ResourceType::ENVIRONMENT, 'production'),
                ResourceType::ENVIRONMENT,
                'env-existing',
                $application,
            ));
    }

    private static function blueprint(): string
    {
        return <<<'YAML'
version: 1
organization: acme
application:
  name: my-api
  region: eu-central-1
  source:
    provider: github
    repository: acme/my-api
environments:
  production:
    branch: main
YAML;
    }

    private static function blueprintWithVariables(): string
    {
        return <<<'YAML'
version: 1
organization: acme
application:
  name: my-api
  region: eu-central-1
  source:
    provider: github
    repository: acme/my-api
environments:
  production:
    branch: main
    variables:
      APP_LITERAL:
        value: literal-secret-value
        sensitive: true
      APP_KEY:
        from_env: LOCAL_APP_KEY
        sensitive: true
YAML;
    }

    private static function blueprintWithVariableUpdate(): string
    {
        return <<<'YAML'
version: 1
organization: acme
application:
  name: my-api
  region: eu-central-1
  source:
    provider: github
    repository: acme/my-api
environments:
  production:
    branch: main
    variables:
      APP_ENV:
        value: desired-update-secret
        sensitive: true
YAML;
    }

    private static function databaseDeletionBlueprint(): string
    {
        return <<<'YAML'
version: 1
organization: acme
application:
  name: my-api
  region: eu-central-1
  source:
    provider: github
    repository: acme/my-api
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
    databases: {}
environments: {}
YAML;
    }

    private static function databaseDeletionState(): StateDocument
    {
        $application = new ResourceAddress(ResourceType::APPLICATION, 'my-api');
        $cluster = new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary');
        return StateDocument::empty()->withOrganization('acme')
            ->withResource(new StateResource($application, ResourceType::APPLICATION, 'app-existing'))
            ->withResource(new StateResource($cluster, ResourceType::DATABASE_CLUSTER, 'cluster-secret-id'))
            ->withResource(new StateResource(
                new ResourceAddress(ResourceType::DATABASE, 'primary.application'),
                ResourceType::DATABASE,
                'database-secret-id',
                $cluster,
            ));
    }
}

final readonly class ApplyCommandFileReader implements FileReader
{
    public function __construct(private string $contents)
    {
    }

    public function exists(string $path): bool
    {
        return true;
    }

    public function read(string $path): string
    {
        return $this->contents;
    }
}

final readonly class ApplyCommandTokenProvider implements CloudTokenProvider
{
    public function __construct(private ?CloudApiToken $token)
    {
    }

    public function token(): ?CloudApiToken
    {
        return $this->token;
    }
}

final readonly class ApplyCommandClientFactory implements LaravelCloudClientFactory
{
    public function __construct(private LaravelCloudClient $cloud)
    {
    }

    public function create(CloudApiToken $token): LaravelCloudClient
    {
        return $this->cloud;
    }
}

class ApplyCommandCloudClient implements LaravelCloudClient
{
    public int $mutationCount = 0;

    /**
     * @param list<CloudApplication> $applications
     * @param list<CloudEnvironment> $environments
     */
    protected function __construct(
        private readonly array $applications,
        protected array $environments,
        private readonly ?CloudValidationException $variableValidationFailure = null,
        private readonly ?CloudEnvironmentVariableCollection $variables = null,
    ) {
    }

    public static function empty(): self
    {
        return new self([], []);
    }

    public static function matching(): self
    {
        return new self(
            [new CloudApplication('app-existing', 'my-api', 'my-api', 'eu-central-1', 'acme/my-api')],
            [new CloudEnvironment('env-existing', 'app-existing', 'production', 'main')],
        );
    }

    public static function withVariableValidationFailure(CloudValidationException $exception): self
    {
        return new self([], [], $exception);
    }

    public static function withVariableUpdate(): self
    {
        return new self(
            [new CloudApplication('app-existing', 'my-api', 'my-api', 'eu-central-1', 'acme/my-api')],
            [new CloudEnvironment('env-existing', 'app-existing', 'production', 'main')],
            variables: new CloudEnvironmentVariableCollection(
                new CloudEnvironmentVariable('APP_ENV', 'remote-update-secret'),
            ),
        );
    }

    public static function withEnvironmentUpdate(): self
    {
        return new self(
            [new CloudApplication('app-existing', 'my-api', 'my-api', 'eu-central-1', 'acme/my-api')],
            [new CloudEnvironment('env-existing', 'app-existing', 'production', 'develop')],
        );
    }

    public static function withSafePreview(): self
    {
        $safe = new EnvironmentDependencies(null, null, null, 0, 0, 0, 0, 0, false, false, true);

        return new self(
            [new CloudApplication('app-existing', 'my-api', 'my-api', 'eu-central-1', 'acme/my-api')],
            [
                new CloudEnvironment('env-existing', 'app-existing', 'production', 'main', dependencies: $safe),
                new CloudEnvironment('env-preview', 'app-existing', 'preview', 'feature', dependencies: $safe),
            ],
        );
    }

    public function organization(): CloudOrganization
    {
        return new CloudOrganization('org-1', 'Acme', 'acme');
    }

    public function applications(): array
    {
        return $this->applications;
    }

    public function environments(string $applicationId): array
    {
        return $this->environments;
    }

    public function environment(string $environmentId): CloudEnvironmentDetails
    {
        return new CloudEnvironmentDetails($environmentId, 'production', $this->variables);
    }

    public function createApplication(CreateApplicationRequest $request): CloudApplication
    {
        ++$this->mutationCount;
        return new CloudApplication('app-created', $request->name, $request->name, $request->region, $request->repository);
    }


    public function createEnvironment(string $applicationId, CreateEnvironmentRequest $request): CloudEnvironment
    {
        ++$this->mutationCount;
        return new CloudEnvironment('env-created', $applicationId, $request->name, $request->branch);
    }

    public function updateEnvironment(string $environmentId, \LaravelCloudBlueprint\Cloud\DTO\UpdateEnvironmentRequest $request): \LaravelCloudBlueprint\Cloud\DTO\UpdatedCloudEnvironment
    {
        ++$this->mutationCount;
        return new \LaravelCloudBlueprint\Cloud\DTO\UpdatedCloudEnvironment($environmentId);
    }

    public function setEnvironmentVariables(string $environmentId, SetEnvironmentVariablesRequest $request): void
    {
        ++$this->mutationCount;
        if ($this->variableValidationFailure !== null) {
            throw $this->variableValidationFailure;
        }
    }

}

final class ApplyCommandEnvironmentDeleteClient extends ApplyCommandCloudClient implements LaravelCloudEnvironmentMutationClient
{
    /** @var list<string> */
    public array $deletedIds = [];

    public static function withInstancePreview(): self
    {
        $dependencies = new EnvironmentDependencies(null, null, null, 0, 1, 0, 0, 0, false, false, true);

        return new self(
            [new CloudApplication('app-existing', 'my-api', 'my-api', 'eu-central-1', 'acme/my-api')],
            [
                new CloudEnvironment('env-existing', 'app-existing', 'production', 'main', dependencies: $dependencies),
                new CloudEnvironment('env-preview', 'app-existing', 'preview', 'feature', dependencies: $dependencies),
            ],
        );
    }

    public function deleteEnvironment(string $environmentId): void
    {
        ++$this->mutationCount;
        $this->deletedIds[] = $environmentId;
        $this->environments = array_values(array_filter(
            $this->environments,
            static fn (CloudEnvironment $environment): bool => $environment->id !== $environmentId,
        ));
    }
}

final class ApplyCommandLogicalDatabaseDeleteClient extends ApplyCommandCloudClient implements LaravelCloudLogicalDatabaseDeletionClient
{
    private bool $deleted = false;

    public static function matching(): self
    {
        return new self(
            [new CloudApplication('app-existing', 'my-api', 'my-api', 'eu-central-1', 'acme/my-api')],
            [],
        );
    }

    public function databaseClusters(): array
    {
        return [new CloudDatabaseCluster(
            'cluster-secret-id',
            'primary',
            'laravel_mysql_8',
            'available',
            'eu-central-1',
            new CloudLaravelMySqlConfiguration('db-flex.m-1vcpu-512mb', 5, 1, false, false),
        )];
    }

    public function databaseCluster(string $clusterId): CloudDatabaseCluster
    {
        throw new \LogicException('Unexpected Database Cluster detail request.');
    }

    public function databases(string $clusterId): array
    {
        return $this->deleted ? [] : [$this->remoteDatabase()];
    }

    public function database(string $clusterId, string $databaseId): CloudDatabase
    {
        if ($this->deleted) {
            throw new CloudResourceNotFoundException('not found', 'GET', '/database', 404);
        }
        return $this->remoteDatabase();
    }

    public function databaseWithDestructiveRelationships(string $clusterId, string $databaseId): CloudDatabase
    {
        return $this->database($clusterId, $databaseId);
    }

    public function deleteDatabase(string $clusterId, string $databaseId): void
    {
        ++$this->mutationCount;
        $this->deleted = true;
    }

    private function remoteDatabase(): CloudDatabase
    {
        return new CloudDatabase(
            'database-secret-id',
            'cluster-secret-id',
            'application',
            'cluster-secret-id',
            [],
            true,
        );
    }
}

final readonly class ApplyCommandEnvironmentValueProvider implements EnvironmentValueProvider
{
    public function value(string $name): string
    {
        return 'resolved-apply-secret';
    }
}

final class ApplyCommandStateStore implements StateStore, StateTransaction
{
    public int $beginCount = 0;
    public int $releaseCount = 0;
    public StateDocument $state;

    public function __construct(
        private readonly StateCorruptedException|StateStorageException|null $loadFailure = null,
    )
    {
        $this->state = StateDocument::empty();
    }

    public function load(): StateDocument
    {
        if ($this->loadFailure !== null) {
            throw $this->loadFailure;
        }
        return $this->state;
    }

    public function save(StateDocument $state): StateDocument
    {
        $this->state = $state->withSerial($this->state->serial + 1);
        return $this->state;
    }

    public function begin(): StateTransaction
    {
        ++$this->beginCount;
        return $this;
    }

    public function release(): void
    {
        ++$this->releaseCount;
    }
}
