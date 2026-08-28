<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Console\Command;

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
use LogicException;
use LaravelCloudBlueprint\Console\Command\CloudInspectCommand;
use LaravelCloudBlueprint\Console\ExitCode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CloudInspectCommandTest extends TestCase
{
    public function testMissingTokenIsHandledCleanly(): void
    {
        $tester = new CommandTester(new CloudInspectCommand(
            new FakeCloudTokenProvider(null),
            new FakeLaravelCloudClientFactory(new FakeLaravelCloudClient()),
        ));

        self::assertSame(ExitCode::GENERAL_ERROR->value, $tester->execute([]));
        self::assertStringContainsString('LCB_TOKEN is not set.', $tester->getDisplay());
    }

    public function testItRendersOrganizationApplicationsAndEnvironmentsUsingReadOnlyOperations(): void
    {
        $client = new FakeLaravelCloudClient();
        $tester = $this->tester($client);

        self::assertSame(ExitCode::SUCCESS->value, $tester->execute([]));
        self::assertStringContainsString("Organization:\n  Acme Organization", $tester->getDisplay());
        self::assertStringContainsString("Applications:\n  API\n    production\n    staging", $tester->getDisplay());
        self::assertSame(['organization', 'applications', 'environments:app-1'], $client->calls);
    }

    public function testJsonModeEmitsStructuredValidJson(): void
    {
        $tester = $this->tester(new FakeLaravelCloudClient());

        self::assertSame(ExitCode::SUCCESS->value, $tester->execute(['--json' => true]));

        $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['organization']);
        self::assertSame('Acme Organization', $decoded['organization']['name']);
        self::assertIsArray($decoded['applications']);
        self::assertIsArray($decoded['applications'][0]);
        self::assertSame('API', $decoded['applications'][0]['name']);
        self::assertIsArray($decoded['applications'][0]['environments']);
        self::assertIsArray($decoded['applications'][0]['environments'][0]);
        self::assertSame('production', $decoded['applications'][0]['environments'][0]['name']);
    }

    public function testJsonErrorsRemainStructuredForMissingTokenAndCloudFailure(): void
    {
        $missing = new CommandTester(new CloudInspectCommand(
            new FakeCloudTokenProvider(null),
            new FakeLaravelCloudClientFactory(new FakeLaravelCloudClient()),
        ));
        self::assertSame(ExitCode::GENERAL_ERROR->value, $missing->execute(['--json' => true]));
        self::assertJsonError($missing, 'LCB_TOKEN is not set.');

        $cloud = $this->tester(new ThrowingInspectCloudClient());
        self::assertSame(ExitCode::GENERAL_ERROR->value, $cloud->execute(['--json' => true]));
        self::assertJsonError($cloud, 'Laravel Cloud is unavailable.');

        foreach ([$missing, $cloud] as $tester) {
            self::assertStringNotContainsString('<error>', $tester->getDisplay());
            self::assertStringNotContainsString('test-token', $tester->getDisplay());
        }
    }

    public function testJsonEncodingFailureProducesSafeValidJson(): void
    {
        $tester = $this->tester(new InvalidUtf8InspectCloudClient());

        self::assertSame(ExitCode::GENERAL_ERROR->value, $tester->execute(['--json' => true]));
        self::assertJsonError($tester, 'Unable to encode command output as JSON.');
        self::assertStringNotContainsString('<error>', $tester->getDisplay());
    }

    private function tester(FakeLaravelCloudClient $client): CommandTester
    {
        return new CommandTester(new CloudInspectCommand(
            new FakeCloudTokenProvider(new CloudApiToken('test-token')),
            new FakeLaravelCloudClientFactory($client),
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
}

final readonly class FakeCloudTokenProvider implements CloudTokenProvider
{
    public function __construct(private ?CloudApiToken $token)
    {
    }

    public function token(): ?CloudApiToken
    {
        return $this->token;
    }
}

final readonly class FakeLaravelCloudClientFactory implements LaravelCloudClientFactory
{
    public function __construct(private LaravelCloudClient $client)
    {
    }

    public function create(CloudApiToken $token): LaravelCloudClient
    {
        return $this->client;
    }
}

class FakeLaravelCloudClient implements LaravelCloudClient
{
    public function updateEnvironment(string $environmentId, \LaravelCloudBlueprint\Cloud\DTO\UpdateEnvironmentRequest $request): \LaravelCloudBlueprint\Cloud\DTO\UpdatedCloudEnvironment
    {
        throw new LogicException('Inspect fake must remain read-only.');
    }
    /** @var list<string> */
    public array $calls = [];

    public function organization(): CloudOrganization
    {
        $this->calls[] = 'organization';
        return new CloudOrganization('org-1', 'Acme Organization', 'acme');
    }

    public function applications(): array
    {
        $this->calls[] = 'applications';
        return [new CloudApplication('app-1', 'API', 'api', 'eu-central-1', 'acme/api')];
    }

    public function environments(string $applicationId): array
    {
        $this->calls[] = 'environments:' . $applicationId;
        return [
            new CloudEnvironment('env-1', $applicationId, 'production', 'main'),
            new CloudEnvironment('env-2', $applicationId, 'staging', null),
        ];
    }

    public function environment(string $environmentId): CloudEnvironmentDetails
    {
        return new CloudEnvironmentDetails($environmentId, 'production', null);
    }

    public function createApplication(CreateApplicationRequest $request): CloudApplication
    {
        throw new LogicException('Read-only fake must not create applications.');
    }

    public function createEnvironment(string $applicationId, CreateEnvironmentRequest $request): CloudEnvironment
    {
        throw new LogicException('Read-only fake must not create environments.');
    }

    public function setEnvironmentVariables(string $environmentId, SetEnvironmentVariablesRequest $request): void
    {
        throw new LogicException('Read-only fake must not set environment variables.');
    }
}

final class ThrowingInspectCloudClient extends FakeLaravelCloudClient
{
    public function organization(): CloudOrganization
    {
        throw new CloudApiException('Laravel Cloud is unavailable.', 'GET', '/meta/organization', 500);
    }
}

final class InvalidUtf8InspectCloudClient extends FakeLaravelCloudClient
{
    public function organization(): CloudOrganization
    {
        return new CloudOrganization('org-1', "invalid-\xB1", 'acme');
    }
}
