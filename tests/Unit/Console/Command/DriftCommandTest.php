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
use LaravelCloudBlueprint\Cloud\DTO\CloudApplication;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironment;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironmentDetails;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironmentVariable;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironmentVariableCollection;
use LaravelCloudBlueprint\Cloud\DTO\CloudOrganization;
use LaravelCloudBlueprint\Cloud\DTO\CreateApplicationRequest;
use LaravelCloudBlueprint\Cloud\DTO\CreateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\DTO\SetEnvironmentVariablesRequest;
use LaravelCloudBlueprint\Cloud\DTO\UpdateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\DTO\UpdatedCloudEnvironment;
use LaravelCloudBlueprint\Cloud\Exception\CloudApiException;
use LaravelCloudBlueprint\Console\Command\DriftCommand;
use LaravelCloudBlueprint\Console\ExitCode;
use LaravelCloudBlueprint\Drift\CreateDriftReport;
use LaravelCloudBlueprint\Infrastructure\Yaml\SymfonyYamlDecoder;
use LaravelCloudBlueprint\Planning\Contract\EnvironmentValueProvider;
use LaravelCloudBlueprint\Planning\CreatePlan;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\Planning\VariableValueResolver;
use LaravelCloudBlueprint\State\Contract\StateStore;
use LaravelCloudBlueprint\State\Contract\StateTransaction;
use LaravelCloudBlueprint\State\StateDocument;
use LaravelCloudBlueprint\State\StateResource;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class DriftCommandTest extends TestCase
{
    public function testHumanReportWithDifferencesSucceedsWithoutCloudOrStateMutation(): void
    {
        $cloud = new DriftCommandCloudClient();
        $states = new DriftCommandStateStore(StateDocument::empty());
        $tester = self::tester(self::blueprint(), $cloud, $states);

        self::assertSame(ExitCode::SUCCESS->value, $tester->execute([]));
        self::assertStringContainsString('Laravel Cloud Blueprint Observations', $tester->getDisplay());
        self::assertStringContainsString('Desired resource missing', $tester->getDisplay());
        self::assertSame(0, $cloud->mutationCalls);
        self::assertSame(0, $states->writeCalls);
    }

    public function testJsonReportHasStableContractAndIncludesInSyncEntries(): void
    {
        $cloud = new DriftCommandCloudClient([
            new CloudApplication('app-id', 'API', null, 'eu-central-1', 'acme/api'),
        ]);
        $state = StateDocument::empty()->withOrganization('acme')->withResource(new StateResource(
            new ResourceAddress(ResourceType::APPLICATION, 'API'),
            ResourceType::APPLICATION,
            'app-id',
        ));
        $tester = self::tester(self::blueprint(), $cloud, new DriftCommandStateStore($state));

        self::assertSame(ExitCode::SUCCESS->value, $tester->execute(['--json' => true]));
        $decoded = self::decoded($tester);
        $summary = $decoded['summary'] ?? null;
        $entries = $decoded['entries'] ?? null;
        if (!is_array($summary) || !is_array($entries) || !isset($entries[0]) || !is_array($entries[0])) {
            throw new LogicException('Successful drift JSON must contain summary and entry arrays.');
        }

        self::assertSame('success', $decoded['status']);
        self::assertSame(1, $summary['in_sync']);
        self::assertSame(1, $summary['total']);
        self::assertSame('application.API', $entries[0]['resource']);
        self::assertSame('in_sync', $entries[0]['observation']);
        self::assertSame('managed', $entries[0]['ownership']);
        self::assertSame(0, $cloud->mutationCalls);
    }

    public function testAlternateBlueprintFileUsesTheExistingFileOption(): void
    {
        $files = new DriftCommandFileReader(self::blueprint());
        $tester = self::tester(self::blueprint(), files: $files);

        self::assertSame(ExitCode::SUCCESS->value, $tester->execute(['--file' => 'alternate.yaml']));
        self::assertSame(['alternate.yaml', 'alternate.yaml'], $files->paths);
    }

    public function testValidationFailureAndOperationalFailureUseEstablishedExitCodesAndJsonErrors(): void
    {
        $invalid = self::tester("version: 1\n");
        self::assertSame(ExitCode::BLUEPRINT_ERROR->value, $invalid->execute(['--json' => true]));
        self::assertSame('validation_failed', self::decoded($invalid)['status']);

        $failure = self::tester(self::blueprint(), new FailingDriftCommandCloudClient());
        self::assertSame(ExitCode::GENERAL_ERROR->value, $failure->execute(['--json' => true]));
        $error = self::decoded($failure);
        self::assertSame(['status' => 'error', 'message' => 'Scoped Cloud discovery failed.'], $error);
        self::assertStringNotContainsString('command-token-sentinel', $failure->getDisplay());
    }

    public function testUnknownIncompleteReportStillExitsSuccessfully(): void
    {
        $tester = self::tester(self::blueprint(), new DriftCommandCloudClient([
            new CloudApplication('app-id', 'API', null, 'eu-central-1', null),
        ]));

        self::assertSame(ExitCode::SUCCESS->value, $tester->execute(['--json' => true]));
        $decoded = self::decoded($tester);
        $summary = $decoded['summary'] ?? null;
        $entries = $decoded['entries'] ?? null;
        if (!is_array($summary) || !is_array($entries) || !isset($entries[0]) || !is_array($entries[0])) {
            throw new LogicException('Successful drift JSON must contain summary and entry arrays.');
        }
        self::assertSame(1, $summary['unknown']);
        self::assertSame('unknown', $entries[0]['observation']);
        self::assertSame('incomplete', $entries[0]['evidence']);
    }

    public function testCheckModeFailsForUnknownObservationsButNormalModeSucceeds(): void
    {
        $cloud = new DriftCommandCloudClient([
            new CloudApplication('app-id', 'API', null, 'eu-central-1', null),
        ]);

        self::assertSame(ExitCode::SUCCESS->value, self::tester(self::blueprint(), $cloud)->execute([]));
        self::assertSame(ExitCode::DRIFT_CHECK_FAILED->value, self::tester(self::blueprint(), $cloud)->execute(['--check' => true]));
    }

    public function testCheckModePassesForAnInSyncReport(): void
    {
        $cloud = new DriftCommandCloudClient([
            new CloudApplication('app-id', 'API', null, 'eu-central-1', 'acme/api'),
        ]);
        $state = StateDocument::empty()->withOrganization('acme')->withResource(new StateResource(
            new ResourceAddress(ResourceType::APPLICATION, 'API'),
            ResourceType::APPLICATION,
            'app-id',
        ));

        $tester = self::tester(self::blueprint(), $cloud, new DriftCommandStateStore($state));
        self::assertSame(ExitCode::SUCCESS->value, $tester->execute(['--check' => true]));
        self::assertStringContainsString('Check passed.', $tester->getDisplay());
        self::assertStringNotContainsString('Check failed:', $tester->getDisplay());
    }

    public function testCheckModeDoesNotChangeJsonOutput(): void
    {
        $cloud = new DriftCommandCloudClient([
            new CloudApplication('app-id', 'API', null, 'eu-central-1', null),
        ]);
        $normal = self::tester(self::blueprint(), $cloud);
        $checked = self::tester(self::blueprint(), $cloud);

        self::assertSame(ExitCode::SUCCESS->value, $normal->execute(['--json' => true]));
        self::assertSame(ExitCode::DRIFT_CHECK_FAILED->value, $checked->execute(['--json' => true, '--check' => true]));
        self::assertSame(self::decoded($normal), self::decoded($checked));
    }

    public function testHumanCheckFailureIncludesEvaluatorCountAndNormalModeHasNoFooter(): void
    {
        $cloud = new DriftCommandCloudClient([
            new CloudApplication('app-id', 'API', null, 'eu-central-1', null),
        ]);
        $normal = self::tester(self::blueprint(), $cloud);
        $checked = self::tester(self::blueprint(), $cloud);

        self::assertSame(ExitCode::SUCCESS->value, $normal->execute([]));
        self::assertStringNotContainsString('Check passed.', $normal->getDisplay());
        self::assertStringNotContainsString('Check failed:', $normal->getDisplay());

        self::assertSame(ExitCode::DRIFT_CHECK_FAILED->value, $checked->execute(['--check' => true]));
        self::assertStringContainsString('Check failed: 1 observations violate the policy.', $checked->getDisplay());
    }

    public function testHumanAndJsonOutputNeverExposeVariableValues(): void
    {
        $blueprint = <<<'YAML'
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
        value: desired-command-secret-9173
        sensitive: true
YAML;
        $cloud = new DriftCommandCloudClient(
            [new CloudApplication('app-id', 'API', null, 'eu-central-1', 'acme/api')],
            [new CloudEnvironment('environment-id-sentinel', 'app-id', 'production', 'main')],
            new CloudEnvironmentDetails(
                'environment-id-sentinel',
                'production',
                new CloudEnvironmentVariableCollection(
                    new CloudEnvironmentVariable('APP_KEY', 'remote-command-secret-2846'),
                ),
            ),
        );

        $human = self::tester($blueprint, $cloud);
        $json = self::tester($blueprint, $cloud);
        self::assertSame(ExitCode::SUCCESS->value, $human->execute([]));
        self::assertSame(ExitCode::SUCCESS->value, $json->execute(['--json' => true]));

        foreach ([$human->getDisplay(), $json->getDisplay()] as $output) {
            self::assertStringContainsString('value', $output);
            self::assertStringNotContainsString('desired-command-secret-9173', $output);
            self::assertStringNotContainsString('remote-command-secret-2846', $output);
            self::assertStringNotContainsString('environment-id-sentinel', $output);
            self::assertStringNotContainsString('command-token-sentinel', $output);
        }
        self::assertSame(0, $cloud->mutationCalls);
    }

    private static function tester(
        string $blueprint,
        LaravelCloudClient $cloud = new DriftCommandCloudClient(),
        ?DriftCommandStateStore $states = null,
        ?DriftCommandFileReader $files = null,
    ): CommandTester {
        $files ??= new DriftCommandFileReader($blueprint);
        $planner = new CreatePlan(new VariableValueResolver(new DriftCommandEnvironment()));

        return new CommandTester(new DriftCommand(
            $files,
            new BlueprintLoader(new SymfonyYamlDecoder(), new BlueprintValidator(), new BlueprintNormalizer()),
            new DriftCommandTokenProvider(),
            new DriftCommandClientFactory($cloud),
            new CreateDriftReport($planner),
            $states ?? new DriftCommandStateStore(StateDocument::empty()),
        ));
    }

    private static function blueprint(): string
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
environments: {}
YAML;
    }

    /** @return array<string, mixed> */
    private static function decoded(CommandTester $tester): array
    {
        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new LogicException('Command output must be a JSON object.');
        }

        $object = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                throw new LogicException('Command output must use JSON object keys.');
            }
            $object[$key] = $value;
        }

        return $object;
    }
}

final class DriftCommandFileReader implements FileReader
{
    /** @var list<string> */
    public array $paths = [];

    public function __construct(private readonly string $contents)
    {
    }

    public function exists(string $path): bool
    {
        $this->paths[] = $path;
        return true;
    }

    public function read(string $path): string
    {
        $this->paths[] = $path;
        return $this->contents;
    }
}

final readonly class DriftCommandTokenProvider implements CloudTokenProvider
{
    public function token(): CloudApiToken
    {
        return new CloudApiToken('command-token-sentinel');
    }
}

final readonly class DriftCommandClientFactory implements LaravelCloudClientFactory
{
    public function __construct(private LaravelCloudClient $cloud)
    {
    }

    public function create(CloudApiToken $token): LaravelCloudClient
    {
        return $this->cloud;
    }
}

final class DriftCommandStateStore implements StateStore
{
    public int $writeCalls = 0;

    public function __construct(private readonly StateDocument $state)
    {
    }

    public function load(): StateDocument
    {
        return $this->state;
    }

    public function save(StateDocument $state): StateDocument
    {
        ++$this->writeCalls;
        throw new LogicException('Drift must not save State.');
    }

    public function begin(): StateTransaction
    {
        ++$this->writeCalls;
        throw new LogicException('Drift must not begin a State transaction.');
    }
}

class DriftCommandCloudClient implements LaravelCloudClient
{
    public int $mutationCalls = 0;

    /** @param list<CloudApplication> $applications */
    public function __construct(
        private readonly array $applications = [],
        /** @var list<CloudEnvironment> */
        private readonly array $environments = [],
        private readonly ?CloudEnvironmentDetails $details = null,
    ) {
    }

    public function organization(): CloudOrganization
    {
        return new CloudOrganization('org-id', 'Acme', 'acme');
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
        return $this->details ?? throw new LogicException('Unexpected Environment detail request.');
    }

    public function createApplication(CreateApplicationRequest $request): CloudApplication
    {
        ++$this->mutationCalls;
        throw new LogicException('Drift must not mutate Cloud.');
    }

    public function createEnvironment(string $applicationId, CreateEnvironmentRequest $request): CloudEnvironment
    {
        ++$this->mutationCalls;
        throw new LogicException('Drift must not mutate Cloud.');
    }

    public function updateEnvironment(string $environmentId, UpdateEnvironmentRequest $request): UpdatedCloudEnvironment
    {
        ++$this->mutationCalls;
        throw new LogicException('Drift must not mutate Cloud.');
    }

    public function setEnvironmentVariables(string $environmentId, SetEnvironmentVariablesRequest $request): void
    {
        ++$this->mutationCalls;
        throw new LogicException('Drift must not mutate Cloud.');
    }
}

final class FailingDriftCommandCloudClient extends DriftCommandCloudClient
{
    public function organization(): CloudOrganization
    {
        throw new CloudApiException('Scoped Cloud discovery failed.', 'GET', '/organization');
    }
}

final readonly class DriftCommandEnvironment implements EnvironmentValueProvider
{
    public function value(string $name): ?string
    {
        return null;
    }
}
