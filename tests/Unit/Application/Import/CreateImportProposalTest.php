<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Application\Import;

use LaravelCloudBlueprint\Application\Import\CreateImportProposal;
use LaravelCloudBlueprint\Application\Import\ImportCandidate;
use LaravelCloudBlueprint\Application\Import\ImportProposal;
use LaravelCloudBlueprint\Application\Import\ImportStatus;
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
use LaravelCloudBlueprint\Cloud\DTO\CloudApplication;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironment;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\State\StateDocument;
use LaravelCloudBlueprint\State\StateResource;
use LaravelCloudBlueprint\State\StateVersion;
use PHPUnit\Framework\TestCase;

final class CreateImportProposalTest extends TestCase
{
    public function testUnmanagedExactMatchesAreImportableAndConfigurationDifferencesDoNotBlockIdentity(): void
    {
        $proposal = self::create(
            StateDocument::empty(),
            [self::application(region: 'different', repository: 'other/repository')],
            [self::environment()],
        );

        self::assertSame(
            [ImportStatus::IMPORTABLE, ImportStatus::IMPORTABLE],
            array_map(static fn (ImportCandidate $candidate): ImportStatus => $candidate->status, self::candidates($proposal)),
        );
        self::assertSame(2, $proposal->countByStatus(ImportStatus::IMPORTABLE));
        self::assertSame('application.my-api', (string) self::candidates($proposal)[1]->parent);
    }

    public function testSameApplicationAddressAndIdentityIsAlreadyManaged(): void
    {
        $candidate = self::candidates(self::create(self::state(self::applicationState()), [self::application()], []))[0];

        self::assertSame(ImportStatus::ALREADY_MANAGED, $candidate->status);
    }

    public function testApplicationAddressWithDifferentIdentityConflicts(): void
    {
        $state = self::state(self::applicationState('app-other'));

        self::assertSame(ImportStatus::CONFLICT, self::candidates(self::create($state, [self::application()], []))[0]->status);
    }

    public function testApplicationRemoteIdentityOwnedByAnotherAddressConflicts(): void
    {
        $state = self::state(new StateResource(
            self::address(ResourceType::APPLICATION, 'other'),
            ResourceType::APPLICATION,
            'app-1',
        ));

        self::assertSame(ImportStatus::CONFLICT, self::candidates(self::create($state, [self::application()], []))[0]->status);
    }

    public function testAmbiguousApplicationsConflictAndPreventChildImport(): void
    {
        $candidates = self::candidates(self::create(
            StateDocument::empty(),
            [self::application(), self::application('app-2')],
            [self::environment()],
        ));

        self::assertSame(ImportStatus::CONFLICT, $candidates[0]->status);
        self::assertSame(ImportStatus::CONFLICT, $candidates[1]->status);
        self::assertStringContainsString('Parent application', $candidates[1]->reason);
    }

    public function testNoApplicationMatchIsUnsupportedAndMakesChildUnsupported(): void
    {
        $candidates = self::candidates(self::create(StateDocument::empty(), [], [self::environment()]));

        self::assertSame(ImportStatus::UNSUPPORTED, $candidates[0]->status);
        self::assertSame(ImportStatus::UNSUPPORTED, $candidates[1]->status);
    }

    public function testSameEnvironmentAddressIdentityAndParentIsAlreadyManaged(): void
    {
        $state = self::state(self::applicationState(), self::environmentState());

        self::assertSame(
            ImportStatus::ALREADY_MANAGED,
            self::candidates(self::create($state, [self::application()], [self::environment()]))[1]->status,
        );
    }

    public function testEnvironmentAddressWithDifferentIdentityConflicts(): void
    {
        $state = self::state(self::applicationState(), self::environmentState('env-other'));

        self::assertSame(
            ImportStatus::CONFLICT,
            self::candidates(self::create($state, [self::application()], [self::environment()]))[1]->status,
        );
    }

    public function testEnvironmentRemoteIdentityOwnedByAnotherAddressConflicts(): void
    {
        $state = self::state(
            self::applicationState(),
            new StateResource(
                self::address(ResourceType::ENVIRONMENT, 'other'),
                ResourceType::ENVIRONMENT,
                'env-1',
                self::address(ResourceType::APPLICATION, 'my-api'),
            ),
        );

        self::assertSame(
            ImportStatus::CONFLICT,
            self::candidates(self::create($state, [self::application()], [self::environment()]))[1]->status,
        );
    }

    public function testEnvironmentWithIncompatibleManagedParentConflicts(): void
    {
        $state = self::state(
            self::applicationState(),
            new StateResource(self::address(ResourceType::APPLICATION, 'other'), ResourceType::APPLICATION, 'app-other'),
            self::environmentState(parentName: 'other'),
        );

        self::assertSame(
            ImportStatus::CONFLICT,
            self::candidates(self::create($state, [self::application()], [self::environment()]))[1]->status,
        );
    }

    public function testMatchingEnvironmentUnderWrongRemoteApplicationConflicts(): void
    {
        $candidate = self::candidates(self::create(
            StateDocument::empty(),
            [self::application()],
            [self::environment(applicationId: 'app-other')],
        ))[1];

        self::assertSame(ImportStatus::CONFLICT, $candidate->status);
        self::assertStringContainsString('different application', $candidate->reason);
    }

    public function testConflictingManagedApplicationPreventsChildImport(): void
    {
        $state = self::state(self::applicationState('app-other'));
        $candidates = self::candidates(self::create($state, [self::application()], [self::environment()]));

        self::assertSame(ImportStatus::CONFLICT, $candidates[0]->status);
        self::assertSame(ImportStatus::CONFLICT, $candidates[1]->status);
    }

    public function testAmbiguousEnvironmentUnderResolvedParentConflicts(): void
    {
        $candidate = self::candidates(self::create(
            StateDocument::empty(),
            [self::application()],
            [self::environment(), self::environment('env-2')],
        ))[1];

        self::assertSame(ImportStatus::CONFLICT, $candidate->status);
    }

    public function testNoEnvironmentMatchIsUnsupported(): void
    {
        self::assertSame(
            ImportStatus::UNSUPPORTED,
            self::candidates(self::create(StateDocument::empty(), [self::application()], []))[1]->status,
        );
    }

    public function testVariablesAreExcludedAndNoValuesAppearInProposal(): void
    {
        $proposal = self::create(StateDocument::empty(), [self::application()], [self::environment()]);
        $serialized = serialize($proposal);

        self::assertCount(2, $proposal);
        self::assertSame(['application', 'environment'], array_map(
            static fn (ImportCandidate $candidate): string => $candidate->type->value,
            self::candidates($proposal),
        ));
        self::assertStringNotContainsString('super-secret-value', $serialized);
        self::assertStringNotContainsString('APP_KEY', $serialized);
    }

    public function testProposalIsDeterministicAndDoesNotMutateState(): void
    {
        $state = self::state(self::applicationState());
        $before = $state->resources();
        $service = new CreateImportProposal();
        $first = $service->create(
            self::blueprint(),
            $state,
            [self::application()],
            [self::environment(name: 'staging', id: 'env-2'), self::environment()],
        );
        $second = $service->create(
            self::blueprint(),
            $state,
            [self::application()],
            [self::environment(), self::environment(name: 'staging', id: 'env-2')],
        );

        self::assertEquals($first, $second);
        self::assertSame(['application.my-api', 'environment.production'], array_map(
            static fn (ImportCandidate $candidate): string => (string) $candidate->address,
            self::candidates($first),
        ));
        self::assertSame($before, $state->resources());
        self::assertSame(0, $state->serial);
    }

    public function testReleasedEnvironmentCanBeProposedForImportAgain(): void
    {
        $state = self::state(self::applicationState(), self::environmentState())
            ->withoutResource(new ResourceAddress(ResourceType::ENVIRONMENT, 'production'));

        $proposal = self::create($state, [self::application()], [self::environment()]);
        $environment = self::candidates($proposal)[1];

        self::assertSame('environment.production', (string) $environment->address);
        self::assertSame(ImportStatus::IMPORTABLE, $environment->status);
    }

    /**
     * @param list<CloudApplication> $applications
     * @param list<CloudEnvironment> $environments
     */
    private static function create(StateDocument $state, array $applications, array $environments): ImportProposal
    {
        return (new CreateImportProposal())->create(self::blueprint(), $state, $applications, $environments);
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
                'main',
                new VariableDefinitionCollection(new VariableDefinition(
                    'APP_KEY',
                    new LiteralVariableValue('super-secret-value'),
                    true,
                )),
            )),
        );
    }

    private static function application(
        string $id = 'app-1',
        string $region = 'eu-central-1',
        ?string $repository = 'acme/my-api',
    ): CloudApplication {
        return new CloudApplication($id, 'my-api', 'my-api', $region, $repository);
    }

    private static function environment(
        string $id = 'env-1',
        string $applicationId = 'app-1',
        string $name = 'production',
    ): CloudEnvironment {
        return new CloudEnvironment($id, $applicationId, $name, 'main');
    }

    private static function applicationState(string $id = 'app-1'): StateResource
    {
        return new StateResource(self::address(ResourceType::APPLICATION, 'my-api'), ResourceType::APPLICATION, $id);
    }

    private static function environmentState(string $id = 'env-1', string $parentName = 'my-api'): StateResource
    {
        return new StateResource(
            self::address(ResourceType::ENVIRONMENT, 'production'),
            ResourceType::ENVIRONMENT,
            $id,
            self::address(ResourceType::APPLICATION, $parentName),
        );
    }

    private static function state(StateResource ...$resources): StateDocument
    {
        return new StateDocument(StateVersion::V1, 0, 'acme', ...$resources);
    }

    private static function address(ResourceType $type, string $name): ResourceAddress
    {
        return new ResourceAddress($type, $name);
    }

    /** @return list<ImportCandidate> */
    private static function candidates(ImportProposal $proposal): array
    {
        return iterator_to_array($proposal, false);
    }
}
