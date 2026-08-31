<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Infrastructure\State;

use LaravelCloudBlueprint\Infrastructure\State\LocalFileStateLock;
use LaravelCloudBlueprint\Infrastructure\State\LocalFileStateStore;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\State\Exception\StateCorruptedException;
use LaravelCloudBlueprint\State\Exception\StateLockedException;
use LaravelCloudBlueprint\State\StateDocument;
use LaravelCloudBlueprint\State\StateResource;
use PHPUnit\Framework\TestCase;

final class LocalFileStateStoreTest extends TestCase
{
    private string $directory;
    private string $path;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/lcb-state-' . bin2hex(random_bytes(8));
        $this->path = $this->directory . '/.lcb/state.json';
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

    public function testMissingStateLoadsAsEmpty(): void
    {
        $state = (new LocalFileStateStore($this->path))->load();

        self::assertSame(0, $state->serial);
        self::assertSame([], $state->resources());
    }

    public function testSavingAndLoadingRoundTripsAndIncrementsSerialOnlyForMaterialChanges(): void
    {
        $store = new LocalFileStateStore($this->path);
        $application = new StateResource(
            new ResourceAddress(ResourceType::APPLICATION, 'my-api'),
            ResourceType::APPLICATION,
            'app_123',
        );
        $environment = new StateResource(
            new ResourceAddress(ResourceType::ENVIRONMENT, 'production'),
            ResourceType::ENVIRONMENT,
            'env_456',
            $application->address,
        );

        $first = $store->save(StateDocument::empty()->withOrganization('acme')->withResource($application));
        self::assertSame(1, $first->serial);

        $unchanged = $store->save($first->withSerial(999));
        self::assertSame(1, $unchanged->serial);

        $second = $store->save($unchanged->withResource($environment));
        self::assertSame(2, $second->serial);

        $loaded = $store->load();
        self::assertSame(2, $loaded->serial);
        self::assertSame('app_123', $loaded->get($application->address)->remoteId);
        self::assertSame('application.my-api', (string) $loaded->get($environment->address)->parent);
    }

    public function testAtomicSaveLeavesValidDeterministicJsonAndNoTemporaryFiles(): void
    {
        $store = new LocalFileStateStore($this->path);
        $environment = new StateResource(
            new ResourceAddress(ResourceType::ENVIRONMENT, 'production'),
            ResourceType::ENVIRONMENT,
            'env_456',
            new ResourceAddress(ResourceType::APPLICATION, 'my-api'),
        );
        $application = new StateResource(
            new ResourceAddress(ResourceType::APPLICATION, 'my-api'),
            ResourceType::APPLICATION,
            'app_123',
        );

        $store->save(StateDocument::empty()
            ->withOrganization('acme')
            ->withResource($environment)
            ->withResource($application));

        $json = file_get_contents($this->path);
        self::assertNotFalse($json);
        self::assertIsArray(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
        self::assertLessThan(
            strpos($json, 'environment.production'),
            strpos($json, 'application.my-api'),
        );
        self::assertSame([], glob($this->directory . '/.lcb/.state-*') ?: []);
        self::assertStringNotContainsString('APP_KEY', $json);
        self::assertStringNotContainsString('secret', $json);
    }

    public function testDatabaseOwnershipGraphRoundTripsInStateVersionOne(): void
    {
        $cluster = new StateResource(
            new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary'),
            ResourceType::DATABASE_CLUSTER,
            'cluster_123',
        );
        $database = new StateResource(
            new ResourceAddress(ResourceType::DATABASE, 'primary.application'),
            ResourceType::DATABASE,
            'database_456',
            $cluster->address,
        );
        $store = new LocalFileStateStore($this->path);

        $store->save(StateDocument::empty()->withOrganization('acme')->withResource($cluster)->withResource($database));
        $loaded = $store->load();

        self::assertSame(1, $loaded->version->value);
        self::assertSame('cluster_123', $loaded->get($cluster->address)->remoteId);
        self::assertSame('database_cluster.primary', (string) $loaded->get($database->address)->parent);
    }

    public function testCorruptJsonFailsWithoutBeingOverwritten(): void
    {
        $this->writeRaw('{broken');
        $store = new LocalFileStateStore($this->path);

        try {
            $store->save(StateDocument::empty()->withOrganization('acme'));
            self::fail('Expected corrupt state to fail.');
        } catch (StateCorruptedException) {
            self::assertSame('{broken', file_get_contents($this->path));
        }
    }

    public function testUnsupportedVersionFailsSafely(): void
    {
        $this->writeRaw('{"version":2,"serial":0,"organization":"acme","resources":{}}');

        $this->expectException(StateCorruptedException::class);
        (new LocalFileStateStore($this->path))->load();
    }

    public function testMissingRequiredResourceFieldFailsSafely(): void
    {
        $this->writeRaw('{"version":1,"serial":1,"organization":"acme","resources":{"application.api":{"type":"application"}}}');

        $this->expectException(StateCorruptedException::class);
        (new LocalFileStateStore($this->path))->load();
    }

    public function testLockPreventsConcurrentAcquisitionAndCanBeReleased(): void
    {
        $lockPath = $this->path . '.lock';
        $firstLock = new LocalFileStateLock($lockPath);
        $secondLock = new LocalFileStateLock($lockPath);
        $handle = $firstLock->acquire();

        try {
            $secondLock->acquire();
            self::fail('Expected the second lock acquisition to fail.');
        } catch (StateLockedException $exception) {
            self::assertSame('Local state is locked by another writer.', $exception->getMessage());
        } finally {
            $handle->release();
        }

        $releasedHandle = $secondLock->acquire();
        $releasedHandle->release();
        $releasedHandle->release();
        self::assertFileExists($lockPath);
    }

    private function writeRaw(string $contents): void
    {
        self::assertTrue(mkdir(dirname($this->path), 0777, true));
        self::assertNotFalse(file_put_contents($this->path, $contents));
    }
}
