<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Integration\Console;

use LaravelCloudBlueprint\Console\ExitCode;
use LaravelCloudBlueprint\Console\LcbApplication;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class BlueprintCommandsTest extends TestCase
{
    private string $originalDirectory;
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $directory = getcwd();
        self::assertNotFalse($directory);
        $this->originalDirectory = $directory;
        $this->temporaryDirectory = sys_get_temp_dir() . '/lcb-tests-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->temporaryDirectory));
        self::assertTrue(chdir($this->temporaryDirectory));
    }

    protected function tearDown(): void
    {
        chdir($this->originalDirectory);

        foreach (glob($this->temporaryDirectory . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        rmdir($this->temporaryDirectory);
    }

    public function testInitCreatesTheDefaultStarterBlueprintAndItPassesValidation(): void
    {
        $init = $this->command('init');

        self::assertSame(ExitCode::SUCCESS->value, $init->execute([]));
        self::assertFileExists('cloud.blueprint.yaml');
        self::assertStringContainsString('Created blueprint file', $init->getDisplay());
        self::assertStringContainsString('region: eu-central-1', (string) file_get_contents('cloud.blueprint.yaml'));

        $validate = $this->command('validate');
        self::assertSame(ExitCode::SUCCESS->value, $validate->execute([]));
        self::assertStringContainsString('Blueprint is valid.', $validate->getDisplay());
    }

    public function testInitRefusesToOverwriteAnExistingFile(): void
    {
        file_put_contents('cloud.blueprint.yaml', 'existing');

        $tester = $this->command('init');

        self::assertSame(ExitCode::GENERAL_ERROR->value, $tester->execute([]));
        self::assertSame('existing', file_get_contents('cloud.blueprint.yaml'));
        self::assertStringContainsString('already exists', $tester->getDisplay());
    }

    public function testInitForceOverwritesAnExistingFile(): void
    {
        file_put_contents('cloud.blueprint.yaml', 'existing');

        $tester = $this->command('init');

        self::assertSame(ExitCode::SUCCESS->value, $tester->execute(['--force' => true]));
        self::assertStringStartsWith('version: 1', (string) file_get_contents('cloud.blueprint.yaml'));
    }

    public function testInitWritesToACustomPath(): void
    {
        $tester = $this->command('init');

        self::assertSame(ExitCode::SUCCESS->value, $tester->execute(['--file' => 'custom.yaml']));
        self::assertFileExists('custom.yaml');
    }

    public function testValidateFailsForAMissingFile(): void
    {
        $tester = $this->command('validate');

        self::assertSame(ExitCode::GENERAL_ERROR->value, $tester->execute([]));
        self::assertStringContainsString('does not exist', $tester->getDisplay());
    }

    public function testValidateReportsAllValidationErrorsAndTheirTotal(): void
    {
        file_put_contents('cloud.blueprint.yaml', <<<'YAML'
version: 2
organization: ''
application:
  name: ''
  region: eu-central-1
  source:
    provider: azure-devops
    repository: ''
environments:
  production: {}
YAML);

        $tester = $this->command('validate');

        self::assertSame(ExitCode::BLUEPRINT_ERROR->value, $tester->execute([]));
        self::assertStringContainsString('[unsupported_version] version:', $tester->getDisplay());
        self::assertStringContainsString('[empty_value] organization:', $tester->getDisplay());
        self::assertStringContainsString('[unsupported_provider] application.source.provider:', $tester->getDisplay());
        self::assertStringContainsString('Blueprint has 6 validation error(s).', $tester->getDisplay());
    }

    public function testValidateReportsYamlErrorsWithoutLeakingSymfonyDetails(): void
    {
        file_put_contents('cloud.blueprint.yaml', "application:\n  name: example\n invalid: indentation\n");

        $tester = $this->command('validate');

        self::assertSame(ExitCode::BLUEPRINT_ERROR->value, $tester->execute([]));
        self::assertStringContainsString('Blueprint YAML could not be decoded.', $tester->getDisplay());
        self::assertStringNotContainsString('Symfony', $tester->getDisplay());
        self::assertStringNotContainsString('ParseException', $tester->getDisplay());
    }

    public function testValidateReadsACustomPath(): void
    {
        self::assertSame(
            ExitCode::SUCCESS->value,
            $this->command('init')->execute(['--file' => 'custom.yaml']),
        );

        $tester = $this->command('validate');

        self::assertSame(ExitCode::SUCCESS->value, $tester->execute(['--file' => 'custom.yaml']));
        self::assertStringContainsString('Blueprint is valid.', $tester->getDisplay());
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester((new LcbApplication())->find($name));
    }
}
