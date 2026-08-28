<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Application\Import;

use LaravelCloudBlueprint\Application\Import\CreateImportProposal;
use LaravelCloudBlueprint\Application\Import\ImportRefusedException;
use LaravelCloudBlueprint\Application\Import\ImportResources;
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
use LaravelCloudBlueprint\Cloud\DTO\UpdateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\DTO\UpdatedCloudEnvironment;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\State\Contract\StateStore;
use LaravelCloudBlueprint\State\Contract\StateTransaction;
use LaravelCloudBlueprint\State\StateDocument;
use LaravelCloudBlueprint\State\StateResource;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ImportResourcesTest extends TestCase
{
    public function testAdoptsApplicationWithoutEnvironments(): void
    {
        $cloud = new ImportResourcesCloud([[self::application()]], ['app-1' => []]);
        $states = new ImportResourcesStateStore();

        $result = self::service()->execute(self::blueprint(false), $cloud, $states);

        self::assertSame(1, $result->adoptedCount());
        self::assertCount(1, $result->state->resources());
        self::assertSame(1, $states->saveCount);
    }

    public function testAdoptsApplicationAndEnvironmentAtomicallyWithOneSave(): void
    {
        $cloud = ImportResourcesCloud::matching();
        $states = new ImportResourcesStateStore();
        $result = self::service()->execute(self::blueprint(), $cloud, $states);

        self::assertSame(2, $result->adoptedCount());
        self::assertSame(1, $states->beginCount);
        self::assertSame(1, $states->saveCount);
        self::assertSame(1, $states->releaseCount);
        self::assertSame(1, $result->state->serial);
        self::assertSame('app-1', $result->state->get(self::address(ResourceType::APPLICATION, 'my-api'))->remoteId);
        $environment = $result->state->get(self::address(ResourceType::ENVIRONMENT, 'production'));
        self::assertSame('env-1', $environment->remoteId);
        self::assertSame('application.my-api', (string) $environment->parent);
        self::assertSame(0, $cloud->mutationCount);
        self::assertSame(['organization', 'applications', 'environments:app-1'], $cloud->calls);
    }

    public function testAlreadyManagedApplicationAndImportableEnvironmentUsesOneFinalSave(): void
    {
        $states = new ImportResourcesStateStore(self::managedApplication());
        $result = self::service()->execute(self::blueprint(), ImportResourcesCloud::matching(), $states);

        self::assertSame(1, $result->adoptedCount());
        self::assertSame(1, $result->alreadyManagedCount());
        self::assertSame(1, $states->saveCount);
        self::assertCount(2, $result->state->resources());
    }

    public function testAllAlreadyManagedIsNoOpWithoutSaveOrSerialChange(): void
    {
        $initial = self::managedApplication()->withResource(new StateResource(
            self::address(ResourceType::ENVIRONMENT, 'production'),
            ResourceType::ENVIRONMENT,
            'env-1',
            self::address(ResourceType::APPLICATION, 'my-api'),
        ));
        $states = new ImportResourcesStateStore($initial);
        $result = self::service()->execute(self::blueprint(), ImportResourcesCloud::matching(), $states);

        self::assertSame(0, $result->adoptedCount());
        self::assertSame(0, $states->saveCount);
        self::assertSame($initial->serial, $result->state->serial);
    }

    public function testEnvironmentConflictBlocksEntireImportWithoutSave(): void
    {
        $states = new ImportResourcesStateStore(StateDocument::empty()->withOrganization('acme')->withResource(
            new StateResource(
                self::address(ResourceType::ENVIRONMENT, 'production'),
                ResourceType::ENVIRONMENT,
                'env-other',
                self::address(ResourceType::APPLICATION, 'my-api'),
            ),
        ));

        try {
            self::service()->execute(self::blueprint(), ImportResourcesCloud::matching(), $states);
            self::fail('Expected import refusal.');
        } catch (ImportRefusedException $exception) {
            self::assertNotNull($exception->proposal);
        }
        self::assertSame(0, $states->saveCount);
        self::assertCount(1, $states->state->resources());
    }

    public function testFreshConflictAfterImportablePreviewWritesNothing(): void
    {
        $cloud = new ImportResourcesCloud(
            applicationSnapshots: [
                [self::application()],
                [self::application(), self::application('app-2')],
            ],
            environments: ['app-1' => [self::environment()], 'app-2' => [self::environment('env-2', 'app-2')]],
        );
        $states = new ImportResourcesStateStore();
        self::assertSame(2, self::service()->preview(self::blueprint(), $cloud, $states)->count());

        $this->expectException(ImportRefusedException::class);
        try {
            self::service()->execute(self::blueprint(), $cloud, $states);
        } finally {
            self::assertSame(0, $states->saveCount);
            self::assertSame([], $states->state->resources());
        }
    }

    public function testFreshRemoteIdentityReplacesStalePreviewIdentity(): void
    {
        $cloud = new ImportResourcesCloud(
            applicationSnapshots: [[self::application()], [self::application('app-new')]],
            environments: [
                'app-1' => [self::environment()],
                'app-new' => [self::environment('env-new', 'app-new')],
            ],
        );
        $states = new ImportResourcesStateStore();
        $preview = self::service()->preview(self::blueprint(), $cloud, $states);
        self::assertSame('app-1', iterator_to_array($preview, false)[0]->remoteId);

        $result = self::service()->execute(self::blueprint(), $cloud, $states);

        self::assertSame('app-new', $result->state->get(self::address(ResourceType::APPLICATION, 'my-api'))->remoteId);
        self::assertSame('env-new', $result->state->get(self::address(ResourceType::ENVIRONMENT, 'production'))->remoteId);
        self::assertStringNotContainsString('super-secret-value', serialize($result));
    }

    private static function service(): ImportResources
    {
        return new ImportResources(new CreateImportProposal());
    }

    private static function blueprint(bool $includeEnvironment = true): Blueprint
    {
        return new Blueprint(
            BlueprintSchemaVersion::V1,
            'acme',
            new ApplicationDefinition('my-api', 'different', new SourceDefinition(SourceProvider::GITHUB, 'other/repository')),
            new EnvironmentDefinitionCollection(...($includeEnvironment ? [new EnvironmentDefinition(
                'production',
                'develop',
                new VariableDefinitionCollection(new VariableDefinition(
                    'APP_KEY',
                    new LiteralVariableValue('super-secret-value'),
                    true,
                )),
            )] : [])),
        );
    }

    public static function application(string $id = 'app-1'): CloudApplication
    {
        return new CloudApplication($id, 'my-api', 'my-api', 'remote-region', 'remote/repository');
    }

    public static function environment(string $id = 'env-1', string $applicationId = 'app-1'): CloudEnvironment
    {
        return new CloudEnvironment($id, $applicationId, 'production', 'main');
    }

    private static function managedApplication(): StateDocument
    {
        return StateDocument::empty()->withOrganization('acme')->withResource(new StateResource(
            self::address(ResourceType::APPLICATION, 'my-api'),
            ResourceType::APPLICATION,
            'app-1',
        ));
    }

    private static function address(ResourceType $type, string $name): ResourceAddress
    {
        return new ResourceAddress($type, $name);
    }
}

final class ImportResourcesStateStore implements StateStore, StateTransaction
{
    public int $beginCount = 0;
    public int $saveCount = 0;
    public int $releaseCount = 0;

    public function __construct(public StateDocument $state = new StateDocument(\LaravelCloudBlueprint\State\StateVersion::V1, 0, null))
    {
    }

    public function load(): StateDocument
    {
        return $this->state;
    }

    public function save(StateDocument $state): StateDocument
    {
        ++$this->saveCount;
        return $this->state = new StateDocument(
            $state->version,
            $this->state->serial + 1,
            $state->organization,
            ...$state->resources(),
        );
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

final class ImportResourcesCloud implements LaravelCloudClient
{
    /** @var list<string> */
    public array $calls = [];
    public int $mutationCount = 0;
    private int $applicationRead = 0;

    /**
     * @param list<list<CloudApplication>> $applicationSnapshots
     * @param array<string, list<CloudEnvironment>> $environments
     */
    public function __construct(private array $applicationSnapshots, private array $environments)
    {
    }

    public static function matching(): self
    {
        return new self([[ImportResourcesTest::application()]], ['app-1' => [ImportResourcesTest::environment()]]);
    }

    public function organization(): CloudOrganization
    {
        $this->calls[] = 'organization';
        return new CloudOrganization('org-1', 'Acme', 'acme');
    }

    public function applications(): array
    {
        $this->calls[] = 'applications';
        $index = min($this->applicationRead++, count($this->applicationSnapshots) - 1);
        return $this->applicationSnapshots[$index];
    }

    public function environments(string $applicationId): array
    {
        $this->calls[] = 'environments:' . $applicationId;
        return $this->environments[$applicationId] ?? [];
    }

    public function environment(string $environmentId): CloudEnvironmentDetails
    {
        throw new RuntimeException('Import must not fetch environment details or variable values.');
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
