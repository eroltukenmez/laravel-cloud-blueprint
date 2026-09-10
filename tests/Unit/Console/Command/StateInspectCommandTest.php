<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Console\Command;

use LaravelCloudBlueprint\Application\BlueprintLoader;
use LaravelCloudBlueprint\Blueprint\Normalization\BlueprintNormalizer;
use LaravelCloudBlueprint\Blueprint\Validation\BlueprintValidator;
use LaravelCloudBlueprint\Cloud\CloudApiToken;
use LaravelCloudBlueprint\Cloud\Contract\CloudTokenProvider;
use LaravelCloudBlueprint\Cloud\Contract\StateInspectionCloudReader;
use LaravelCloudBlueprint\Cloud\Contract\StateInspectionCloudReaderFactory;
use LaravelCloudBlueprint\Cloud\DTO\CloudApplication;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabase;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabaseCluster;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironment;
use LaravelCloudBlueprint\Cloud\DTO\CloudUnknownDatabaseConfiguration;
use LaravelCloudBlueprint\Cloud\Exception\CloudTransportException;
use LaravelCloudBlueprint\Console\Command\StateInspectCommand;
use LaravelCloudBlueprint\Console\ExitCode;
use LaravelCloudBlueprint\Infrastructure\File\NativeFileSystem;
use LaravelCloudBlueprint\Infrastructure\State\LocalFileStateStore;
use LaravelCloudBlueprint\Infrastructure\Yaml\SymfonyYamlDecoder;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\State\StateDocument;
use LaravelCloudBlueprint\State\StateResource;
use LaravelCloudBlueprint\State\StateVersion;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class StateInspectCommandTest extends TestCase
{
    private string $directory;
    private string $path;
    private LocalFileStateStore $states;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/lcb-state-inspect-' . bin2hex(random_bytes(8));
        $this->path = $this->directory . '/.lcb/state.json';
        $this->states = new LocalFileStateStore($this->path);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/.lcb/*') ?: [] as $file) {
            is_file($file) && unlink($file);
        }
        is_dir($this->directory . '/.lcb') && rmdir($this->directory . '/.lcb');
        is_dir($this->directory) && rmdir($this->directory);
    }

    public function testLocalOnlyInspectionAndCheckNeedNoCloudAndDoNotWriteState(): void
    {
        $this->saveState();
        $before = file_get_contents($this->path);
        $tester = $this->tester(null, null);

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Cloud verification: not requested', $tester->getDisplay());
        self::assertSame(0, $tester->execute(['--check' => true]));
        self::assertSame($before, file_get_contents($this->path));
    }

    public function testCommandDependsOnOnlyTheReadOnlyCloudFactoryCapability(): void
    {
        $parameter = (new \ReflectionClass(StateInspectCommand::class))->getConstructor()?->getParameters()[2];

        self::assertNotNull($parameter);
        self::assertInstanceOf(\ReflectionNamedType::class, $parameter->getType());
        self::assertSame(StateInspectionCloudReaderFactory::class, $parameter->getType()->getName());
    }

    public function testLocalOnlyCheckFailsForUnhealthyState(): void
    {
        mkdir(dirname($this->path), 0777, true);
        file_put_contents($this->path, json_encode([
            'version' => 1, 'serial' => 0, 'organization' => 'acme',
            'resources' => ['application.api' => ['type' => 'application', 'remote_id' => 'app-1']],
        ], JSON_THROW_ON_ERROR));

        $tester = $this->tester(null, null);
        self::assertSame(ExitCode::CHECK_FAILED->value, $tester->execute(['--check' => true]));
    }

    public function testJsonPreservesV1ContractAndDoesNotExposeRemoteIds(): void
    {
        $this->saveState();
        $tester = $this->tester(null, null);

        self::assertSame(0, $tester->execute(['--json' => true]));
        $json = self::json($tester);
        self::assertSame(1, $json['contract_version'] ?? null);
        self::assertIsArray($json['cloud'] ?? null);
        self::assertSame('not_requested', $json['cloud']['evidence'] ?? null);
        self::assertStringNotContainsString('app-secret-id', $tester->getDisplay());
    }

    public function testJsonCheckChangesOnlyExitStatusAndNotReportBytes(): void
    {
        $this->saveState();
        $normal = $this->tester(null, null);
        $checked = $this->tester(null, null);

        self::assertSame(ExitCode::SUCCESS->value, $normal->execute(['--json' => true]));
        self::assertSame(ExitCode::SUCCESS->value, $checked->execute(['--json' => true, '--check' => true]));
        self::assertSame($normal->getDisplay(), $checked->getDisplay());
    }

    public function testCloudInspectionIsCompleteAndDiscoversSharedApplicationOnce(): void
    {
        $this->saveState(twoEnvironments: true);
        $cloud = new StateInspectReader();
        $tester = $this->tester(new StateInspectTokenProvider(), $cloud);
        $before = file_get_contents($this->path);

        self::assertSame(0, $tester->execute(['--cloud' => true, '--check' => true]));
        self::assertStringContainsString('Cloud verification: complete', $tester->getDisplay());
        self::assertSame(['applications', 'environments:app-secret-id', 'databaseClusters'], $cloud->calls);
        self::assertSame($before, file_get_contents($this->path));
    }

    public function testPartialCloudReadProducesIncompleteReportAndStrictCheckFailure(): void
    {
        $this->saveState();
        $normal = $this->tester(new StateInspectTokenProvider(), new StateInspectReader(failEnvironments: true));
        $tester = $this->tester(new StateInspectTokenProvider(), new StateInspectReader(failEnvironments: true));

        self::assertSame(ExitCode::SUCCESS->value, $normal->execute(['--cloud' => true, '--json' => true]));
        self::assertSame(ExitCode::CHECK_FAILED->value, $tester->execute(['--cloud' => true, '--check' => true, '--json' => true]));
        self::assertSame($normal->getDisplay(), $tester->getDisplay());
        $json = self::json($tester);
        self::assertIsArray($json['cloud'] ?? null);
        self::assertSame('incomplete', $json['cloud']['evidence'] ?? null);
        self::assertIsArray($json['diagnostics'] ?? null);
        self::assertContains('evidence_incomplete', array_column($json['diagnostics'], 'code'));
    }

    public function testMissingCloudTokenIsOperationalFailureAndInvalidBlueprintIsBlueprintFailure(): void
    {
        $this->saveState();
        self::assertSame(ExitCode::GENERAL_ERROR->value, $this->tester(null, new StateInspectReader())->execute(['--cloud' => true]));

        $blueprint = $this->directory . '/invalid.yaml';
        file_put_contents($blueprint, 'not: [valid');
        self::assertSame(ExitCode::BLUEPRINT_ERROR->value, $this->tester(new StateInspectTokenProvider(), new StateInspectReader())->execute(['--file' => $blueprint]));
        unlink($blueprint);
    }

    public function testJsonMissingFileTokenAndCorruptStateUseVersionedErrors(): void
    {
        $this->saveState();

        $missing = $this->tester(null, null);
        self::assertSame(ExitCode::GENERAL_ERROR->value, $missing->execute([
            '--file' => $this->directory . '/missing.yaml',
            '--json' => true,
        ]));
        $missingError = self::json($missing);
        self::assertIsArray($missingError['error']);
        self::assertSame('filesystem', $missingError['error']['category']);
        self::assertSame('file_not_found', $missingError['error']['code']);

        $token = $this->tester(null, new StateInspectReader());
        self::assertSame(ExitCode::GENERAL_ERROR->value, $token->execute(['--cloud' => true, '--json' => true]));
        $tokenError = self::json($token);
        self::assertIsArray($tokenError['error']);
        self::assertSame('authentication', $tokenError['error']['category']);
        self::assertSame('authentication_token_missing', $tokenError['error']['code']);

        file_put_contents($this->path, '{broken');
        $corrupt = $this->tester(null, null);
        self::assertSame(ExitCode::GENERAL_ERROR->value, $corrupt->execute(['--json' => true]));
        $stateError = self::json($corrupt);
        self::assertIsArray($stateError['error']);
        self::assertSame('state', $stateError['error']['category']);
        self::assertSame('state_corrupted', $stateError['error']['code']);
    }

    private function tester(?CloudTokenProvider $token, ?StateInspectionCloudReader $reader): CommandTester
    {
        return new CommandTester(new StateInspectCommand(
            $this->states,
            $token ?? new StateInspectTokenProvider(null),
            new StateInspectReaderFactory($reader ?? new StateInspectReader()),
            new NativeFileSystem(),
            new BlueprintLoader(new SymfonyYamlDecoder(), new BlueprintValidator(), new BlueprintNormalizer()),
        ));
    }

    private function saveState(bool $twoEnvironments = false): void
    {
        $application = new ResourceAddress(ResourceType::APPLICATION, 'api');
        $resources = [new StateResource($application, ResourceType::APPLICATION, 'app-secret-id'), new StateResource(
            new ResourceAddress(ResourceType::ENVIRONMENT, 'production'), ResourceType::ENVIRONMENT, 'env-secret-id', $application,
        )];
        if ($twoEnvironments) {
            $resources[] = new StateResource(new ResourceAddress(ResourceType::ENVIRONMENT, 'staging'), ResourceType::ENVIRONMENT, 'env-staging-id', $application);
        }
        $this->states->save(new StateDocument(StateVersion::V2, 0, 'acme', ...$resources));
    }

    /** @return array<string, mixed> */
    private static function json(CommandTester $tester): array
    {
        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame(1, $decoded['contract_version'] ?? null);
        $result = [];
        foreach ($decoded as $key => $value) {
            self::assertIsString($key);
            $result[$key] = $value;
        }
        return $result;
    }
}

final readonly class StateInspectTokenProvider implements CloudTokenProvider
{
    public function __construct(private ?CloudApiToken $token = new CloudApiToken('state-inspect-token')) {}
    public function token(): ?CloudApiToken { return $this->token; }
}

final readonly class StateInspectReaderFactory implements StateInspectionCloudReaderFactory
{
    public function __construct(private StateInspectionCloudReader $reader) {}
    public function create(CloudApiToken $token): StateInspectionCloudReader { return $this->reader; }
}

final class StateInspectReader implements StateInspectionCloudReader
{
    /** @var list<string> */
    public array $calls = [];
    public function __construct(private bool $failEnvironments = false) {}
    public function applications(): array { $this->calls[] = 'applications'; return [new CloudApplication('app-secret-id', 'api', null, 'region', null)]; }
    public function environments(string $applicationId): array { $this->calls[] = 'environments:' . $applicationId; if ($this->failEnvironments) throw new CloudTransportException('unavailable', 'GET', ''); return [new CloudEnvironment('env-secret-id', $applicationId, 'production', 'main'), new CloudEnvironment('env-staging-id', $applicationId, 'staging', 'main')]; }
    public function databaseClusters(): array { $this->calls[] = 'databaseClusters'; return []; }
    public function databaseCluster(string $clusterId): CloudDatabaseCluster { throw new \LogicException('No cluster read is expected.'); }
    public function databases(string $clusterId): array { throw new \LogicException('No database list is expected.'); }
    public function database(string $clusterId, string $databaseId): CloudDatabase { throw new \LogicException('No database read is expected.'); }
}
