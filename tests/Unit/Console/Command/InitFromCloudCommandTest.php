<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Console\Command;

use LaravelCloudBlueprint\Application\CloudBlueprintExporter;
use LaravelCloudBlueprint\Blueprint\SourceProvider;
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
use LaravelCloudBlueprint\Cloud\Exception\CloudAuthenticationException;
use LaravelCloudBlueprint\Console\Command\InitCommand;
use LaravelCloudBlueprint\Console\ExitCode;
use LaravelCloudBlueprint\Console\Template\StarterBlueprintTemplate;
use LaravelCloudBlueprint\Infrastructure\File\NativeFileSystem;
use LaravelCloudBlueprint\Infrastructure\Yaml\SymfonyBlueprintYamlEncoder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class InitFromCloudCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/lcb-cloud-init-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        if (is_dir($this->directory . '/.lcb')) {
            foreach (glob($this->directory . '/.lcb/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($this->directory . '/.lcb');
        }
        rmdir($this->directory);
    }

    public function testOneApplicationIsSelectedAndNoStateIsCreated(): void
    {
        $cloud = self::cloud([
            new CloudApplication('app-secret-id', 'API', 'api', 'eu-central-1', 'acme/api', SourceProvider::GITHUB),
        ]);

        $tester = $this->tester($cloud);
        $path = $this->directory . '/generated.yaml';
        self::assertSame(ExitCode::SUCCESS->value, $tester->execute([
            '--from-cloud' => true,
            '--file' => $path,
            '--non-interactive' => true,
        ]));

        $yaml = (string) file_get_contents($path);
        self::assertStringContainsString('organization: acme', $yaml);
        self::assertStringContainsString('name: API', $yaml);
        self::assertStringContainsString('region: eu-central-1', $yaml);
        self::assertStringContainsString('provider: github', $yaml);
        self::assertStringContainsString('repository: acme/api', $yaml);
        self::assertStringContainsString('production:', $yaml);
        self::assertStringContainsString('branch: main', $yaml);
        self::assertStringContainsString(
            "version: 1\n\norganization: acme\n\napplication:",
            $yaml,
        );
        self::assertStringContainsString("repository: acme/api\n\nenvironments:\n", $yaml);
        self::assertDoesNotMatchRegularExpression('/[ \t]+$/m', $yaml);
        self::assertStringEndsWith("branch: main\n", $yaml);
        self::assertFalse(str_ends_with($yaml, "\n\n"));
        self::assertStringNotContainsString('variables', $yaml);
        self::assertStringNotContainsString('secret', $yaml);
        self::assertFileDoesNotExist($this->directory . '/.lcb/state.json');
        self::assertSame(0, $cloud->mutationCalls);
    }

    public function testMultipleApplicationsRequireSelectionWhenNonInteractive(): void
    {
        $tester = $this->tester(self::cloud(self::applications()));

        self::assertSame(ExitCode::GENERAL_ERROR->value, $tester->execute([
            '--from-cloud' => true,
            '--file' => $this->directory . '/blueprint.yaml',
            '--non-interactive' => true,
        ]));
        self::assertStringContainsString('Supply --application', $tester->getDisplay());
    }

    public function testExactApplicationNameSelectsApplication(): void
    {
        $tester = $this->tester(self::cloud(self::applications()));
        $path = $this->directory . '/selected.yaml';

        self::assertSame(ExitCode::SUCCESS->value, $tester->execute([
            '--from-cloud' => true,
            '--application' => 'Worker',
            '--file' => $path,
            '--non-interactive' => true,
        ]));
        self::assertStringContainsString('name: Worker', (string) file_get_contents($path));
    }

    public function testMissingExactApplicationFailsSafely(): void
    {
        $tester = $this->tester(self::cloud(self::applications()));

        self::assertSame(ExitCode::GENERAL_ERROR->value, $tester->execute([
            '--from-cloud' => true,
            '--application' => 'missing',
            '--file' => $this->directory . '/blueprint.yaml',
        ]));
        self::assertFileDoesNotExist($this->directory . '/blueprint.yaml');
    }

    public function testInteractiveMultipleApplicationSelectionUsesNumberedList(): void
    {
        $tester = $this->tester(self::cloud(self::applications()));
        $tester->setInputs(['2']);
        $path = $this->directory . '/interactive.yaml';

        self::assertSame(ExitCode::SUCCESS->value, $tester->execute([
            '--from-cloud' => true,
            '--file' => $path,
        ]));
        self::assertStringContainsString('1. API', $tester->getDisplay());
        self::assertStringContainsString('2. Worker', $tester->getDisplay());
        self::assertStringContainsString('name: Worker', (string) file_get_contents($path));
    }

    public function testProviderOptionSupportsApiThatDoesNotExposeProvider(): void
    {
        $cloud = self::cloud([new CloudApplication('app', 'API', 'api', 'eu-central-1', 'acme/api')]);
        $path = $this->directory . '/provider.yaml';

        self::assertSame(ExitCode::SUCCESS->value, $this->tester($cloud)->execute([
            '--from-cloud' => true,
            '--provider' => 'gitlab',
            '--file' => $path,
        ]));
        self::assertStringContainsString('provider: gitlab', (string) file_get_contents($path));
    }

    public function testExistingFileRulesForceAndExistingStateRemainSafe(): void
    {
        $path = $this->directory . '/blueprint.yaml';
        file_put_contents($path, 'existing');
        self::assertTrue(mkdir($this->directory . '/.lcb'));
        $statePath = $this->directory . '/.lcb/state.json';
        file_put_contents($statePath, 'existing-state');
        $tester = $this->tester(self::cloud([self::applications()[0]]));

        self::assertSame(ExitCode::GENERAL_ERROR->value, $tester->execute([
            '--from-cloud' => true,
            '--file' => $path,
        ]));
        self::assertSame('existing', file_get_contents($path));

        self::assertSame(ExitCode::SUCCESS->value, $tester->execute([
            '--from-cloud' => true,
            '--file' => $path,
            '--force' => true,
        ]));
        self::assertSame('existing-state', file_get_contents($statePath));
    }

    public function testMissingTokenFailsBeforeCloudAccess(): void
    {
        $tester = $this->tester(self::cloud([]), false);

        self::assertSame(ExitCode::GENERAL_ERROR->value, $tester->execute([
            '--from-cloud' => true,
            '--file' => $this->directory . '/blueprint.yaml',
        ]));
        self::assertStringContainsString('LCB_TOKEN is not set', $tester->getDisplay());
    }

    public function testCloudErrorIsSafeAndDoesNotWriteOutput(): void
    {
        $cloud = self::cloud([], fail: true);
        $tester = $this->tester($cloud);
        $path = $this->directory . '/blueprint.yaml';

        self::assertSame(ExitCode::GENERAL_ERROR->value, $tester->execute([
            '--from-cloud' => true,
            '--file' => $path,
        ]));
        self::assertStringContainsString('authentication', $tester->getDisplay());
        self::assertStringNotContainsString('test-token', $tester->getDisplay());
        self::assertFileDoesNotExist($path);
    }

    private function tester(InitExportCloud $cloud, bool $hasToken = true): CommandTester
    {
        $files = new NativeFileSystem();
        $command = new InitCommand(
            $files,
            $files,
            new StarterBlueprintTemplate(),
            new InitExportTokenProvider($hasToken),
            new InitExportClientFactory($cloud),
            new CloudBlueprintExporter(),
            new SymfonyBlueprintYamlEncoder(),
        );
        $application = new Application();
        $application->add($command);

        return new CommandTester($command);
    }

    /** @return list<CloudApplication> */
    private static function applications(): array
    {
        return [
            new CloudApplication('app-1', 'API', 'api', 'eu-central-1', 'acme/api', SourceProvider::GITHUB),
            new CloudApplication('app-2', 'Worker', 'worker', 'us-east-1', 'acme/worker', SourceProvider::BITBUCKET),
        ];
    }

    /** @param list<CloudApplication> $applications */
    private static function cloud(array $applications, bool $fail = false): InitExportCloud
    {
        return new InitExportCloud($applications, $fail);
    }
}

final readonly class InitExportTokenProvider implements CloudTokenProvider
{
    public function __construct(private bool $available) {}
    public function token(): ?CloudApiToken { return $this->available ? new CloudApiToken('test-token') : null; }
}

final readonly class InitExportClientFactory implements LaravelCloudClientFactory
{
    public function __construct(private InitExportCloud $cloud) {}
    public function create(CloudApiToken $token): LaravelCloudClient { return $this->cloud; }
}

final class InitExportCloud implements LaravelCloudClient
{
    public function updateApplication(string $applicationId, \LaravelCloudBlueprint\Cloud\DTO\UpdateApplicationRequest $request): CloudApplication
    {
        throw new \LogicException('Export fake must remain read-only.');
    }
    public function updateEnvironment(string $environmentId, \LaravelCloudBlueprint\Cloud\DTO\UpdateEnvironmentRequest $request): \LaravelCloudBlueprint\Cloud\DTO\UpdatedCloudEnvironment
    {
        throw new \LogicException('Export fake must remain read-only.');
    }
    public int $mutationCalls = 0;
    /** @param list<CloudApplication> $applicationList */
    public function __construct(private array $applicationList, private bool $fail = false) {}
    public function organization(): CloudOrganization
    {
        if ($this->fail) {
            throw new CloudAuthenticationException('Laravel Cloud authentication failed.', 'GET', '/meta/organization', 401);
        }
        return new CloudOrganization('org-secret-id', 'Acme', 'acme');
    }
    public function applications(): array { return $this->applicationList; }
    public function environments(string $applicationId): array
    {
        return [new CloudEnvironment('env-secret-id', $applicationId, 'production', 'main')];
    }
    public function environment(string $environmentId): CloudEnvironmentDetails { throw new \LogicException('Unexpected read.'); }
    public function createApplication(CreateApplicationRequest $request): CloudApplication { ++$this->mutationCalls; throw new \LogicException('Mutation called.'); }
    public function createEnvironment(string $applicationId, CreateEnvironmentRequest $request): CloudEnvironment { ++$this->mutationCalls; throw new \LogicException('Mutation called.'); }
    public function setEnvironmentVariables(string $environmentId, SetEnvironmentVariablesRequest $request): void { ++$this->mutationCalls; throw new \LogicException('Mutation called.'); }
}
