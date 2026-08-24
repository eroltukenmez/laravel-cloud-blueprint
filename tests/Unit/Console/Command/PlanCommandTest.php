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
use LaravelCloudBlueprint\Cloud\DTO\CloudOrganization;
use LaravelCloudBlueprint\Cloud\DTO\CreateApplicationRequest;
use LaravelCloudBlueprint\Cloud\DTO\CreateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\DTO\SetEnvironmentVariablesRequest;
use LaravelCloudBlueprint\Cloud\Exception\CloudApiException;
use LaravelCloudBlueprint\Console\Command\PlanCommand;
use LaravelCloudBlueprint\Console\ExitCode;
use LaravelCloudBlueprint\Infrastructure\Yaml\SymfonyYamlDecoder;
use LaravelCloudBlueprint\Planning\CreatePlan;
use LaravelCloudBlueprint\Planning\Contract\EnvironmentValueProvider;
use LaravelCloudBlueprint\Planning\VariableValueResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use LogicException;

final class PlanCommandTest extends TestCase
{
    public function testItRendersATextPlanWithoutExposingTheToken(): void
    {
        $tester = $this->tester(self::validBlueprint(applicationName: 'new-api'));

        self::assertSame(ExitCode::SUCCESS->value, $tester->execute([]));
        self::assertStringContainsString('Laravel Cloud Blueprint Plan', $tester->getDisplay());
        self::assertStringContainsString('+ application.new-api', $tester->getDisplay());
        self::assertStringContainsString('+ environment.production', $tester->getDisplay());
        self::assertStringContainsString('Plan: 2 to create, 0 unchanged, 0 unsupported.', $tester->getDisplay());
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
        self::assertIsArray($decoded['actions']);
        self::assertIsArray($decoded['actions'][0]);
        self::assertSame('application.new-api', $decoded['actions'][0]['resource']);
        self::assertSame('create', $decoded['actions'][0]['operation']);
    }

    public function testMissingTokenFailsSafely(): void
    {
        $tester = $this->tester(self::validBlueprint(), null);

        self::assertSame(ExitCode::GENERAL_ERROR->value, $tester->execute([]));
        self::assertStringContainsString('LCB_TOKEN is not set.', $tester->getDisplay());
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

    private function tester(
        string $blueprint,
        ?CloudApiToken $token = new CloudApiToken('super-secret-token'),
        LaravelCloudClient $cloud = new PlanCommandCloudClient(),
    ): CommandTester {
        return new CommandTester(new PlanCommand(
            new PlanFileReader($blueprint),
            new BlueprintLoader(new SymfonyYamlDecoder(), new BlueprintValidator(), new BlueprintNormalizer()),
            new PlanTokenProvider($token),
            new PlanClientFactory($cloud),
            new CreatePlan(new VariableValueResolver(new PlanEnvironmentValueProvider())),
        ));
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
}

final readonly class PlanFileReader implements FileReader
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
