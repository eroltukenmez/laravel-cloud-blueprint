<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Console\Command;

use LaravelCloudBlueprint\Application\BlueprintLoader;
use LaravelCloudBlueprint\Application\File\FileReader;
use LaravelCloudBlueprint\Application\Import\CreateImportProposal;
use LaravelCloudBlueprint\Application\Import\ImportResources;
use LaravelCloudBlueprint\Blueprint\Normalization\BlueprintNormalizer;
use LaravelCloudBlueprint\Blueprint\Validation\BlueprintValidator;
use LaravelCloudBlueprint\Cloud\CloudApiToken;
use LaravelCloudBlueprint\Cloud\Contract\CloudTokenProvider;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudClient;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudClientFactory;
use LaravelCloudBlueprint\Cloud\DTO\CloudApplication;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironment;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironmentDetails;
use LaravelCloudBlueprint\Cloud\DTO\CloudOrganization;
use LaravelCloudBlueprint\Cloud\DTO\CreateApplicationRequest;
use LaravelCloudBlueprint\Cloud\DTO\CreateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\DTO\SetEnvironmentVariablesRequest;
use LaravelCloudBlueprint\Cloud\DTO\UpdateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\DTO\UpdatedCloudEnvironment;
use LaravelCloudBlueprint\Console\Command\ImportCommand;
use LaravelCloudBlueprint\Console\ExitCode;
use LaravelCloudBlueprint\Infrastructure\Yaml\SymfonyYamlDecoder;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\State\Contract\StateStore;
use LaravelCloudBlueprint\State\Contract\StateTransaction;
use LaravelCloudBlueprint\State\StateDocument;
use LaravelCloudBlueprint\State\StateResource;
use LaravelCloudBlueprint\State\StateVersion;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class ImportCommandTest extends TestCase
{
    public function testInteractiveDeclineRendersSafePreviewAndWritesNothing(): void
    {
        [$tester, $cloud, $states] = self::tester();
        $tester->setInputs(['no']);

        self::assertSame(ExitCode::SUCCESS->value, $tester->execute([]));
        self::assertSame(0, $states->beginCount);
        self::assertSame(0, $states->saveCount);
        self::assertSame(0, $cloud->mutationCount);
        self::assertStringContainsString('+ application.my-api', $tester->getDisplay());
        self::assertStringContainsString('+ environment.production', $tester->getDisplay());
        self::assertStringContainsString('Cloud resources will not be modified', $tester->getDisplay());
        self::assertStringNotContainsString('app-secret-id', $tester->getDisplay());
        self::assertStringNotContainsString('literal-secret-value', $tester->getDisplay());
        self::assertStringNotContainsString('super-secret-token', $tester->getDisplay());
    }

    public function testAutoApproveAdoptsApplicationAndEnvironmentAfterFreshDiscovery(): void
    {
        [$tester, $cloud, $states] = self::tester();

        self::assertSame(ExitCode::SUCCESS->value, $tester->execute(['--auto-approve' => true]));
        self::assertSame(2, $cloud->applicationReads);
        self::assertSame(2, $cloud->environmentReads);
        self::assertSame(1, $states->beginCount);
        self::assertSame(1, $states->saveCount);
        self::assertSame(1, $states->state->serial);
        self::assertSame('application.my-api', (string) $states->state->get(
            new ResourceAddress(ResourceType::ENVIRONMENT, 'production'),
        )->parent);
        self::assertStringNotContainsString('Import these existing resources', $tester->getDisplay());
        self::assertStringContainsString('2 adopted, 0 already managed', $tester->getDisplay());
        self::assertSame(0, $cloud->mutationCount);
    }

    public function testInteractiveApprovalPermitsImport(): void
    {
        [$tester, , $states] = self::tester();
        $tester->setInputs(['yes']);

        self::assertSame(ExitCode::SUCCESS->value, $tester->execute([]));
        self::assertSame(1, $states->saveCount);
    }

    public function testJsonReportsSafeIdentitiesAndActuallyAdoptedResources(): void
    {
        [$tester, , $states] = self::tester();

        self::assertSame(ExitCode::SUCCESS->value, $tester->execute(['--json' => true, '--auto-approve' => true]));
        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['summary']);
        self::assertIsArray($decoded['resources']);
        self::assertIsArray($decoded['resources'][0]);
        self::assertSame('success', $decoded['status']);
        self::assertSame(2, $decoded['summary']['adopted']);
        self::assertSame('app-secret-id', $decoded['resources'][0]['remote_id']);
        self::assertTrue($decoded['resources'][0]['adopted']);
        self::assertNull($decoded['resources'][0]['reason']);
        self::assertStringNotContainsString('literal-secret-value', $tester->getDisplay());
        self::assertStringNotContainsString('super-secret-token', $tester->getDisplay());
        self::assertSame(1, $states->saveCount);
    }

    public function testConflictRefusesEntireImportWithoutPromptOrStateTransaction(): void
    {
        $state = StateDocument::empty()->withOrganization('acme')->withResource(new StateResource(
            new ResourceAddress(ResourceType::ENVIRONMENT, 'production'),
            ResourceType::ENVIRONMENT,
            'different-environment-id',
            new ResourceAddress(ResourceType::APPLICATION, 'my-api'),
        ));
        [$tester, $cloud, $states] = self::tester($state);

        self::assertSame(ExitCode::GENERAL_ERROR->value, $tester->execute(['--auto-approve' => true]));
        self::assertStringContainsString('! environment.production', $tester->getDisplay());
        self::assertStringContainsString('Import refused', $tester->getDisplay());
        self::assertSame(0, $states->beginCount);
        self::assertSame(0, $states->saveCount);
        self::assertSame(0, $cloud->mutationCount);
    }

    public function testUnsupportedCandidatesAreRenderedAndRefuseImport(): void
    {
        [$tester, , $states] = self::tester(cloud: new ImportCommandCloud(false));

        self::assertSame(ExitCode::GENERAL_ERROR->value, $tester->execute(['--auto-approve' => true]));
        self::assertStringContainsString('! application.my-api', $tester->getDisplay());
        self::assertStringContainsString('unsupported', $tester->getDisplay());
        self::assertSame(0, $states->beginCount);
    }

    public function testAllAlreadyManagedIsNoOpWithoutPromptSaveOrSerialIncrement(): void
    {
        $state = StateDocument::empty()->withOrganization('acme')
            ->withResource(new StateResource(
                new ResourceAddress(ResourceType::APPLICATION, 'my-api'),
                ResourceType::APPLICATION,
                'app-secret-id',
            ))
            ->withResource(new StateResource(
                new ResourceAddress(ResourceType::ENVIRONMENT, 'production'),
                ResourceType::ENVIRONMENT,
                'env-secret-id',
                new ResourceAddress(ResourceType::APPLICATION, 'my-api'),
            ));
        [$tester, , $states] = self::tester($state);

        self::assertSame(ExitCode::SUCCESS->value, $tester->execute([]));
        self::assertStringContainsString('Nothing to import', $tester->getDisplay());
        self::assertSame(0, $states->beginCount);
        self::assertSame(0, $states->saveCount);
        self::assertSame(0, $states->state->serial);
    }

    public function testNonInteractiveRequiresAutoApproveWhenImportable(): void
    {
        [$tester, , $states] = self::tester();

        self::assertSame(ExitCode::GENERAL_ERROR->value, $tester->execute(['--non-interactive' => true]));
        self::assertStringContainsString('requires --auto-approve', $tester->getDisplay());
        self::assertSame(0, $states->beginCount);
    }

    /** @return array{CommandTester, ImportCommandCloud, ImportCommandStateStore} */
    private static function tester(?StateDocument $initial = null, ?ImportCommandCloud $cloud = null): array
    {
        $cloud ??= new ImportCommandCloud();
        $states = new ImportCommandStateStore($initial ?? StateDocument::empty());
        $command = new ImportCommand(
            new ImportCommandFileReader(),
            new BlueprintLoader(new SymfonyYamlDecoder(), new BlueprintValidator(), new BlueprintNormalizer()),
            new ImportCommandTokenProvider(),
            new ImportCommandClientFactory($cloud),
            new ImportResources(new CreateImportProposal()),
            $states,
        );
        $application = new Application();
        $application->add($command);
        return [new CommandTester($application->find('import')), $cloud, $states];
    }
}

final readonly class ImportCommandFileReader implements FileReader
{
    public function exists(string $path): bool
    {
        return true;
    }

    public function read(string $path): string
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
    branch: develop
    variables:
      APP_KEY:
        value: literal-secret-value
        sensitive: true
YAML;
    }
}

final readonly class ImportCommandTokenProvider implements CloudTokenProvider
{
    public function token(): CloudApiToken
    {
        return new CloudApiToken('super-secret-token');
    }
}

final readonly class ImportCommandClientFactory implements LaravelCloudClientFactory
{
    public function __construct(private LaravelCloudClient $cloud)
    {
    }

    public function create(CloudApiToken $token): LaravelCloudClient
    {
        return $this->cloud;
    }
}

final class ImportCommandStateStore implements StateStore, StateTransaction
{
    public int $beginCount = 0;
    public int $saveCount = 0;

    public function __construct(public StateDocument $state)
    {
    }

    public function load(): StateDocument
    {
        return $this->state;
    }

    public function save(StateDocument $state): StateDocument
    {
        ++$this->saveCount;
        return $this->state = new StateDocument(StateVersion::V1, $this->state->serial + 1, $state->organization, ...$state->resources());
    }

    public function begin(): StateTransaction
    {
        ++$this->beginCount;
        return $this;
    }

    public function release(): void
    {
    }
}

final class ImportCommandCloud implements LaravelCloudClient
{
    public int $applicationReads = 0;
    public int $environmentReads = 0;
    public int $mutationCount = 0;

    public function __construct(private bool $matching = true)
    {
    }

    public function organization(): CloudOrganization
    {
        return new CloudOrganization('org-id', 'Acme', 'acme');
    }

    public function applications(): array
    {
        ++$this->applicationReads;
        return $this->matching
            ? [new CloudApplication('app-secret-id', 'my-api', 'my-api', 'remote-region', 'other/repository')]
            : [];
    }

    public function environments(string $applicationId): array
    {
        ++$this->environmentReads;
        return [new CloudEnvironment('env-secret-id', 'app-secret-id', 'production', 'main')];
    }

    public function environment(string $environmentId): CloudEnvironmentDetails
    {
        throw new RuntimeException('Import must not read variable values.');
    }

    public function createApplication(CreateApplicationRequest $request): CloudApplication
    {
        ++$this->mutationCount;
        throw new RuntimeException('Import must remain read-only.');
    }

    public function createEnvironment(string $applicationId, CreateEnvironmentRequest $request): CloudEnvironment
    {
        ++$this->mutationCount;
        throw new RuntimeException('Import must remain read-only.');
    }

    public function updateEnvironment(string $environmentId, UpdateEnvironmentRequest $request): UpdatedCloudEnvironment
    {
        ++$this->mutationCount;
        throw new RuntimeException('Import must remain read-only.');
    }

    public function setEnvironmentVariables(string $environmentId, SetEnvironmentVariablesRequest $request): void
    {
        ++$this->mutationCount;
        throw new RuntimeException('Import must remain read-only.');
    }
}
