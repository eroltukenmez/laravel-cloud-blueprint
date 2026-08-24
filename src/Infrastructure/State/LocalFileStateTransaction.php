<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Infrastructure\State;

use LaravelCloudBlueprint\State\Contract\StateLockHandle;
use LaravelCloudBlueprint\State\Contract\StateTransaction;
use LaravelCloudBlueprint\State\StateDocument;

final class LocalFileStateTransaction implements StateTransaction
{
    private bool $released = false;

    public function __construct(
        private readonly LocalFileStateStore $store,
        private readonly StateLockHandle $lock,
    ) {
    }

    public function load(): StateDocument
    {
        return $this->store->load();
    }

    public function save(StateDocument $state): StateDocument
    {
        return $this->store->saveWhileLocked($state);
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $this->lock->release();
        $this->released = true;
    }

    public function __destruct()
    {
        $this->release();
    }
}
