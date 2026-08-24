<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\State\Contract;

interface StateLock
{
    public function acquire(): StateLockHandle;
}
