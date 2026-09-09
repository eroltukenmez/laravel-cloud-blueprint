<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Console\Command;

use JsonException;
use LaravelCloudBlueprint\Application\State\ReleaseStateOwnership;
use LaravelCloudBlueprint\Application\State\StateOwnershipReleaseRefusedException;
use LaravelCloudBlueprint\Console\Command\StateUnmanageCommand;
use LaravelCloudBlueprint\Console\ExitCode;
use LaravelCloudBlueprint\Infrastructure\State\LocalFileStateStore;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\State\StateDocument;
use LaravelCloudBlueprint\State\StateResource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class StateUnmanageCommandTest extends TestCase
{
    private string $directory;
    private string $path;
    private LocalFileStateStore $states;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/lcb-unmanage-' . bin2hex(random_bytes(8));
        $this->path = $this->directory . '/.lcb/state.json';
        $this->states = new LocalFileStateStore($this->path);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/.lcb/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->directory . '/.lcb')) {
            rmdir($this->directory . '/.lcb');
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    #[DataProvider('childAddressProvider')]
    public function testChildOwnershipReleaseIsStateOnlyAndIncrementsSerialOnce(string $address): void
    {
        $initial = $this->saveState();
        $tester = $this->tester();

        self::assertSame(ExitCode::SUCCESS->value, $tester->execute([
            'address' => $address,
            '--auto-approve' => true,
        ]));

        $loaded = $this->states->load();
        self::assertNull($loaded->find(ResourceAddress::fromString($address)));
        self::assertSame($initial->serial + 1, $loaded->serial);
        self::assertStringContainsString('only the local LCB State ownership', $tester->getDisplay());
        self::assertStringContainsString('was not modified or deleted', $tester->getDisplay());
        self::assertStringNotContainsString('env-secret-id', $tester->getDisplay());
        self::assertStringNotContainsString('database-secret-id', $tester->getDisplay());
    }

    /** @return iterable<string, array{string}> */
    public static function childAddressProvider(): iterable
    {
        yield 'Environment' => ['environment.production'];
        yield 'logical Database' => ['database.primary.application'];
    }

    #[DataProvider('parentAddressProvider')]
    public function testParentWithManagedChildIsRefusedAndBytesRemainStable(string $address, string $child): void
    {
        $this->saveState();
        $before = file_get_contents($this->path);
        $tester = $this->tester();

        self::assertSame(ExitCode::GENERAL_ERROR->value, $tester->execute([
            'address' => $address,
            '--auto-approve' => true,
        ]));

        self::assertSame($before, file_get_contents($this->path));
        self::assertStringContainsString($child, $tester->getDisplay());
        self::assertStringContainsString('No Laravel Cloud resource was modified.', $tester->getDisplay());
    }

    /** @return iterable<string, array{string, string}> */
    public static function parentAddressProvider(): iterable
    {
        yield 'Application' => ['application.my-api', 'environment.production'];
        yield 'Database Cluster' => ['database_cluster.primary', 'database.primary.application'];
    }

    public function testMissingStateAndRepeatedReleaseAreIdempotentNoChanges(): void
    {
        $missing = $this->tester();
        self::assertSame(ExitCode::SUCCESS->value, $missing->execute(['address' => 'environment.production']));
        self::assertStringContainsString('not currently managed', $missing->getDisplay());
        self::assertFileDoesNotExist($this->path);

        $this->saveState();
        $first = $this->tester();
        self::assertSame(0, $first->execute(['address' => 'environment.production', '--auto-approve' => true]));
        $before = file_get_contents($this->path);
        $repeat = $this->tester();
        self::assertSame(0, $repeat->execute(['address' => 'environment.production']));
        self::assertSame($before, file_get_contents($this->path));
    }

    public function testHistoricalDottedStateAddressCanBeUnmanaged(): void
    {
        mkdir(dirname($this->path), 0777, true);
        file_put_contents($this->path, '{"version":1,"serial":0,"organization":"acme","resources":{'
            . '"application.api":{"type":"application","remote_id":"app-id"},'
            . '"environment.foo.bar":{"type":"environment","remote_id":"environment-id",'
            . '"parent":"application.api"}}}');

        $tester = $this->tester();

        self::assertSame(ExitCode::SUCCESS->value, $tester->execute([
            'address' => 'environment.foo.bar',
            '--auto-approve' => true,
        ]));
        self::assertNull($this->states->load()->find(ResourceAddress::fromString('environment.foo.bar')));
    }

    #[DataProvider('invalidAddressProvider')]
    public function testMalformedAndNonStateAddressesAreErrorsWithoutWrites(string $address, string $message): void
    {
        $this->saveState();
        $before = file_get_contents($this->path);
        $tester = $this->tester();

        self::assertSame(ExitCode::GENERAL_ERROR->value, $tester->execute(['address' => $address]));
        self::assertStringContainsString($message, $tester->getDisplay());
        self::assertSame($before, file_get_contents($this->path));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidAddressProvider(): iterable
    {
        yield 'malformed' => ['production', 'Invalid State resource address'];
        yield 'Variable' => ['variable.APP_KEY', 'not State-owned'];
        yield 'Database attachment' => ['database_attachment.production', 'not State-owned'];
    }

    public function testMalformedStateIsErrorAndUntouched(): void
    {
        mkdir(dirname($this->path), 0777, true);
        file_put_contents($this->path, '{broken');
        $tester = $this->tester();

        self::assertSame(ExitCode::GENERAL_ERROR->value, $tester->execute(['address' => 'environment.production']));
        self::assertSame('{broken', file_get_contents($this->path));
    }

    public function testInteractiveCancellationAndNonInteractiveRefusalAreByteStable(): void
    {
        $this->saveState();
        $before = file_get_contents($this->path);
        $cancelled = $this->tester();
        $cancelled->setInputs(['no']);

        self::assertSame(ExitCode::SUCCESS->value, $cancelled->execute(['address' => 'environment.production']));
        self::assertStringContainsString('cancelled', $cancelled->getDisplay());
        self::assertSame($before, file_get_contents($this->path));

        $refused = $this->tester();
        self::assertSame(ExitCode::GENERAL_ERROR->value, $refused->execute([
            'address' => 'environment.production',
            '--non-interactive' => true,
        ]));
        self::assertStringContainsString('requires --auto-approve', $refused->getDisplay());
        self::assertSame($before, file_get_contents($this->path));
    }

    public function testConcurrentRevalidationRefusalPreservesPersistedStateBytes(): void
    {
        $this->saveState();
        $service = new ReleaseStateOwnership();
        $proposal = $service->preview(
            ResourceAddress::fromString('environment.production'),
            $this->states,
        );
        $this->states->save(
            $this->states->load()->withoutResource(ResourceAddress::fromString('environment.production')),
        );
        $before = file_get_contents($this->path);

        try {
            $service->execute($proposal, $this->states);
            self::fail('Expected stale ownership proposal refusal.');
        } catch (StateOwnershipReleaseRefusedException) {
            self::assertSame($before, file_get_contents($this->path));
        }
    }

    public function testNonInteractiveAutoApproveSucceedsWithoutToken(): void
    {
        $this->saveState();
        $tester = $this->tester();

        self::assertSame(ExitCode::SUCCESS->value, $tester->execute([
            'address' => 'environment.production',
            '--non-interactive' => true,
            '--auto-approve' => true,
        ]));
        self::assertStringNotContainsString('LCB_TOKEN', $tester->getDisplay());
    }

    public function testJsonSuccessNoChangesAndParentRefusalArePureAndSecretFree(): void
    {
        $this->saveState();
        $success = $this->tester();
        self::assertSame(0, $success->execute([
            'address' => 'environment.production', '--json' => true, '--auto-approve' => true,
        ]));
        $successJson = self::json($success);
        self::assertSame('success', $successJson['status']);
        self::assertTrue($successJson['released']);
        $resource = $successJson['resource'];
        self::assertIsArray($resource);
        self::assertSame('application.my-api', $resource['parent']);

        $noChanges = $this->tester();
        self::assertSame(0, $noChanges->execute(['address' => 'environment.production', '--json' => true]));
        self::assertSame('no_changes', self::json($noChanges)['status']);

        $refusal = $this->tester();
        self::assertSame(1, $refusal->execute([
            'address' => 'database_cluster.primary', '--json' => true, '--auto-approve' => true,
        ]));
        $refusalJson = self::json($refusal);
        self::assertSame('refused', $refusalJson['status']);
        self::assertSame(['database.primary.application'], $refusalJson['children']);

        foreach ([$success->getDisplay(), $noChanges->getDisplay(), $refusal->getDisplay()] as $output) {
            self::assertStringNotContainsString('secret-id', $output);
            self::assertStringNotContainsString('password', $output);
        }
    }

    public function testJsonActionRequiresAutoApproveAndEncodingFallbackIsValidJson(): void
    {
        $this->saveState();
        $before = file_get_contents($this->path);
        $refused = $this->tester();
        self::assertSame(1, $refused->execute(['address' => 'environment.production', '--json' => true]));
        self::assertSame('refused', self::json($refused)['status']);
        self::assertSame($before, file_get_contents($this->path));

        $fallback = $this->tester();
        self::assertSame(1, $fallback->execute(['address' => "environment.\xB1", '--json' => true]));
        self::json($fallback);
        self::assertStringContainsString('Unable to encode', $fallback->getDisplay());
    }

    private function tester(): CommandTester
    {
        $application = new Application();
        $application->add(new StateUnmanageCommand(new ReleaseStateOwnership(), $this->states));

        return new CommandTester($application->find('state:unmanage'));
    }

    private function saveState(): StateDocument
    {
        $application = new ResourceAddress(ResourceType::APPLICATION, 'my-api');
        $cluster = new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary');

        return $this->states->save(new StateDocument(
            \LaravelCloudBlueprint\State\StateVersion::V1,
            0,
            'acme',
            new StateResource($application, ResourceType::APPLICATION, 'app-secret-id'),
            new StateResource(
                new ResourceAddress(ResourceType::ENVIRONMENT, 'production'),
                ResourceType::ENVIRONMENT,
                'env-secret-id',
                $application,
            ),
            new StateResource($cluster, ResourceType::DATABASE_CLUSTER, 'cluster-secret-id'),
            new StateResource(
                new ResourceAddress(ResourceType::DATABASE, 'primary.application'),
                ResourceType::DATABASE,
                'database-secret-id',
                $cluster,
            ),
        ));
    }

    /** @return array<string, mixed> */
    private static function json(CommandTester $tester): array
    {
        try {
            $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            self::fail('Command did not produce pure JSON: ' . $exception->getMessage());
        }
        self::assertIsArray($decoded);
        $result = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                self::fail('Command JSON must be an object with string keys.');
            }
            $result[$key] = $value;
        }
        return $result;
    }
}
