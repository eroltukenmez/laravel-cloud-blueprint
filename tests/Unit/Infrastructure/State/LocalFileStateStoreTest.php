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
use LaravelCloudBlueprint\State\StateOwnershipClassification;
use LaravelCloudBlueprint\State\StateProvenance;
use LaravelCloudBlueprint\State\StateResource;
use LaravelCloudBlueprint\State\StateVersion;
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
            ->withResource($application)
            ->withResource($environment));

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

    public function testDatabaseOwnershipGraphRoundTripsInCanonicalStateVersionTwo(): void
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

        self::assertSame(2, $loaded->version->value);
        self::assertSame('cluster_123', $loaded->get($cluster->address)->remoteId);
        self::assertSame('database_cluster.primary', (string) $loaded->get($database->address)->parent);
        self::assertSame(StateOwnershipClassification::MANAGED, $loaded->get($database->address)->classification);
        self::assertNull($loaded->get($database->address)->provenance);
    }

    public function testVersionOneLoadsLazilyAsOrdinaryVersionTwoWithoutRewritingTheFile(): void
    {
        $v1 = '{"version":1,"serial":7,"organization":"acme","resources":{'
            . '"database_cluster.primary":{"type":"database_cluster","remote_id":"cluster_123"},'
            . '"database.primary.production":{"type":"database","remote_id":"database_456",'
            . '"parent":"database_cluster.primary"}}}';
        $this->writeRaw($v1);

        $loaded = (new LocalFileStateStore($this->path))->load();
        $database = $loaded->get(new ResourceAddress(ResourceType::DATABASE, 'primary.production'));

        self::assertSame(StateVersion::V2, $loaded->version);
        self::assertSame(7, $loaded->serial);
        self::assertSame(StateOwnershipClassification::MANAGED, $database->classification);
        self::assertNull($database->provenance);
        self::assertSame('database_cluster.primary', (string) $database->parent);
        self::assertSame($v1, file_get_contents($this->path));
    }

    public function testDerivedResourceRoundTripsWithTypedProvenanceAndParentGraph(): void
    {
        $clusterAddress = new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary');
        $derivedAddress = new ResourceAddress(ResourceType::DATABASE, 'primary.__derived_default');
        $store = new LocalFileStateStore($this->path);

        $saved = $store->save(StateDocument::empty()->withOrganization('acme')
            ->withResource(new StateResource($clusterAddress, ResourceType::DATABASE_CLUSTER, 'cluster_123'))
            ->withResource(new StateResource(
                $derivedAddress,
                ResourceType::DATABASE,
                'database_456',
                $clusterAddress,
                StateOwnershipClassification::DERIVED,
                StateProvenance::CLUSTER_CREATE_RESPONSE,
            )));
        $loaded = $store->load();
        $derived = $loaded->get($derivedAddress);

        self::assertSame(StateVersion::V2, $saved->version);
        self::assertSame(StateOwnershipClassification::DERIVED, $derived->classification);
        self::assertSame(StateProvenance::CLUSTER_CREATE_RESPONSE, $derived->provenance);
        self::assertSame('database_cluster.primary', (string) $derived->parent);
        $json = file_get_contents($this->path);
        self::assertIsString($json);
        self::assertStringContainsString('"version": 2', $json);
        self::assertStringContainsString('"classification": "derived"', $json);
        self::assertStringContainsString('"provenance": "cluster_create_response"', $json);
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
        $this->writeRaw('{"version":3,"serial":0,"organization":"acme","resources":{}}');

        $this->expectException(StateCorruptedException::class);
        (new LocalFileStateStore($this->path))->load();
    }

    public function testVersionTwoRejectsInvalidClassification(): void
    {
        $this->writeRaw('{"version":2,"serial":0,"organization":"acme","resources":{'
            . '"application.api":{"type":"application","remote_id":"app_1","classification":"future"}}}');

        $this->expectException(StateCorruptedException::class);
        (new LocalFileStateStore($this->path))->load();
    }

    public function testVersionTwoRejectsInvalidProvenance(): void
    {
        $this->writeRaw('{"version":2,"serial":0,"organization":"acme","resources":{'
            . '"database_cluster.primary":{"type":"database_cluster","remote_id":"cluster_1","classification":"managed"},'
            . '"database.primary.derived":{"type":"database","remote_id":"database_1",'
            . '"parent":"database_cluster.primary","classification":"derived","provenance":"future"}}}');

        $this->expectException(StateCorruptedException::class);
        (new LocalFileStateStore($this->path))->load();
    }

    public function testVersionTwoRejectsDerivedWithoutProvenance(): void
    {
        $this->writeRaw('{"version":2,"serial":0,"organization":"acme","resources":{'
            . '"database_cluster.primary":{"type":"database_cluster","remote_id":"cluster_1","classification":"managed"},'
            . '"database.primary.derived":{"type":"database","remote_id":"database_1",'
            . '"parent":"database_cluster.primary","classification":"derived"}}}');

        $this->expectException(StateCorruptedException::class);
        (new LocalFileStateStore($this->path))->load();
    }

    public function testVersionTwoRejectsOrdinaryResourceWithDerivedProvenance(): void
    {
        $this->writeRaw('{"version":2,"serial":0,"organization":"acme","resources":{'
            . '"database_cluster.primary":{"type":"database_cluster","remote_id":"cluster_1","classification":"managed"},'
            . '"database.primary.application":{"type":"database","remote_id":"database_1",'
            . '"parent":"database_cluster.primary","classification":"managed",'
            . '"provenance":"cluster_create_response"}}}');

        $this->expectException(StateCorruptedException::class);
        (new LocalFileStateStore($this->path))->load();
    }

    public function testVersionTwoRejectsUnknownResourceFields(): void
    {
        $this->writeRaw('{"version":2,"serial":0,"organization":"acme","resources":{'
            . '"application.api":{"type":"application","remote_id":"app_1",'
            . '"classification":"managed","metadata":{}}}}');

        $this->expectException(StateCorruptedException::class);
        (new LocalFileStateStore($this->path))->load();
    }

    public function testMissingRequiredResourceFieldFailsSafely(): void
    {
        $this->writeRaw('{"version":1,"serial":1,"organization":"acme","resources":{"application.api":{"type":"application"}}}');

        $this->expectException(StateCorruptedException::class);
        (new LocalFileStateStore($this->path))->load();
    }

    public function testEnvironmentWithUnownedApplicationParentIsMalformedState(): void
    {
        $this->writeRaw('{"version":1,"serial":1,"organization":"acme","resources":{"environment.production":{"type":"environment","remote_id":"env_123","parent":"application.my-api"}}}');

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
