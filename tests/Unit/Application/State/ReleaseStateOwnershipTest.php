<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Application\State;

use LaravelCloudBlueprint\Application\State\ReleaseStateOwnership;
use LaravelCloudBlueprint\Application\State\StateOwnershipReleaseRefusedException;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\State\Contract\StateStore;
use LaravelCloudBlueprint\State\Contract\StateTransaction;
use LaravelCloudBlueprint\State\Exception\StateLockedException;
use LaravelCloudBlueprint\State\Exception\StateStorageException;
use LaravelCloudBlueprint\State\StateDocument;
use LaravelCloudBlueprint\State\StateResource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReleaseStateOwnershipTest extends TestCase
{
    public function testEnvironmentAndLogicalDatabaseCanBeReleasedIndividually(): void
    {
        foreach ([self::environmentAddress(), self::databaseAddress()] as $address) {
            $states = new ReleaseStateStore(self::state());
            $service = new ReleaseStateOwnership();

            $result = $service->execute($service->preview($address, $states), $states);

            self::assertNull($result->state->find($address));
            self::assertSame(1, $result->state->serial);
            self::assertSame(1, $states->saveCount);
            self::assertSame(['begin', 'load-current', 'save', 'release'], $states->events);
        }
    }

    public function testParentReleaseIsRefusedForApplicationAndDatabaseCluster(): void
    {
        foreach ([self::applicationAddress(), self::clusterAddress()] as $address) {
            $states = new ReleaseStateStore(self::state());
            $service = new ReleaseStateOwnership();
            $proposal = $service->preview($address, $states);

            self::assertFalse($proposal->canRelease());
            self::assertCount(1, $proposal->children());

            try {
                $service->execute($proposal, $states);
                self::fail('Expected ownership release refusal.');
            } catch (StateOwnershipReleaseRefusedException) {
                self::assertSame(0, $states->saveCount);
            }
        }
    }

    /** @return iterable<string, array{ResourceAddress, StateDocument, callable(StateDocument): StateDocument}> */
    public static function concurrentChangeProvider(): iterable
    {
        yield 'resource disappeared' => [
            self::environmentAddress(),
            self::state(),
            static fn (StateDocument $state): StateDocument => $state->withoutResource(self::environmentAddress()),
        ];
        yield 'remote ID changed' => [
            self::environmentAddress(),
            self::state(),
            static fn (StateDocument $state): StateDocument => $state->withResource(new StateResource(
                self::environmentAddress(), ResourceType::ENVIRONMENT, 'env-changed', self::applicationAddress(),
            )),
        ];
        yield 'parent changed' => [
            self::environmentAddress(),
            self::state(),
            static function (StateDocument $state): StateDocument {
                $other = new ResourceAddress(ResourceType::APPLICATION, 'other');
                return $state
                    ->withResource(new StateResource($other, ResourceType::APPLICATION, 'app-other'))
                    ->withResource(new StateResource(
                        self::environmentAddress(), ResourceType::ENVIRONMENT, 'env-1', $other,
                    ));
            },
        ];
        yield 'child added' => [
            self::applicationAddress(),
            self::state()->withoutResource(self::environmentAddress()),
            static fn (StateDocument $state): StateDocument => $state->withResource(new StateResource(
                new ResourceAddress(ResourceType::ENVIRONMENT, 'staging'),
                ResourceType::ENVIRONMENT,
                'env-staging',
                self::applicationAddress(),
            )),
        ];
        yield 'child removed' => [
            self::applicationAddress(),
            self::state(),
            static fn (StateDocument $state): StateDocument => $state->withoutResource(self::environmentAddress()),
        ];
    }

    /** @param callable(StateDocument): StateDocument $change */
    #[DataProvider('concurrentChangeProvider')]
    public function testConcurrentStateChangesRefuseWithoutSaving(
        ResourceAddress $target,
        StateDocument $previewState,
        callable $change,
    ): void
    {
        $current = $change($previewState);
        $states = new ReleaseStateStore($previewState, $current);
        $service = new ReleaseStateOwnership();
        $before = serialize($states->current);

        $this->expectException(StateOwnershipReleaseRefusedException::class);
        try {
            $service->execute($service->preview($target, $states), $states);
        } finally {
            self::assertSame(0, $states->saveCount);
            self::assertSame(['begin', 'load-current', 'release'], $states->events);
            self::assertSame($before, serialize($states->current));
        }
    }

    public function testSaveAndLockFailuresPreserveCurrentState(): void
    {
        $service = new ReleaseStateOwnership();
        $saveFailure = new ReleaseStateStore(self::state(), failSave: true);
        $approved = $service->preview(self::environmentAddress(), $saveFailure);
        try {
            $service->execute($approved, $saveFailure);
            self::fail('Expected save failure.');
        } catch (StateStorageException) {
            self::assertNotNull($saveFailure->current->find(self::environmentAddress()));
            self::assertSame(0, $saveFailure->current->serial);
        }

        $lockFailure = new ReleaseStateStore(self::state(), failLock: true);
        $approved = $service->preview(self::environmentAddress(), $lockFailure);
        $this->expectException(StateLockedException::class);
        $service->execute($approved, $lockFailure);
    }

    private static function state(): StateDocument
    {
        return new StateDocument(
            \LaravelCloudBlueprint\State\StateVersion::V1,
            0,
            'acme',
            new StateResource(self::applicationAddress(), ResourceType::APPLICATION, 'app-1'),
            new StateResource(self::environmentAddress(), ResourceType::ENVIRONMENT, 'env-1', self::applicationAddress()),
            new StateResource(self::clusterAddress(), ResourceType::DATABASE_CLUSTER, 'cluster-1'),
            new StateResource(self::databaseAddress(), ResourceType::DATABASE, 'database-1', self::clusterAddress()),
        );
    }

    private static function applicationAddress(): ResourceAddress
    {
        return new ResourceAddress(ResourceType::APPLICATION, 'my-api');
    }

    private static function environmentAddress(): ResourceAddress
    {
        return new ResourceAddress(ResourceType::ENVIRONMENT, 'production');
    }

    private static function clusterAddress(): ResourceAddress
    {
        return new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary');
    }

    private static function databaseAddress(): ResourceAddress
    {
        return new ResourceAddress(ResourceType::DATABASE, 'primary.application');
    }
}

final class ReleaseStateStore implements StateStore, StateTransaction
{
    /** @var list<string> */
    public array $events = [];
    public int $saveCount = 0;
    public StateDocument $current;

    public function __construct(
        private readonly StateDocument $preview,
        ?StateDocument $current = null,
        private readonly bool $failSave = false,
        private readonly bool $failLock = false,
    ) {
        $this->current = $current ?? $preview;
    }

    public function load(): StateDocument
    {
        if ($this->events === []) {
            return $this->preview;
        }
        $this->events[] = 'load-current';
        return $this->current;
    }

    public function save(StateDocument $state): StateDocument
    {
        ++$this->saveCount;
        $this->events[] = 'save';
        if ($this->failSave) {
            throw new StateStorageException('save failed');
        }
        $this->current = $state->withSerial($this->current->serial + 1);
        return $this->current;
    }

    public function begin(): StateTransaction
    {
        if ($this->failLock) {
            throw new StateLockedException('locked');
        }
        $this->events[] = 'begin';
        return $this;
    }

    public function release(): void
    {
        $this->events[] = 'release';
    }
}
