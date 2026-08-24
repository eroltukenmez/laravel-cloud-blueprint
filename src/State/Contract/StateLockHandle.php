<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\State\Contract;

interface StateLockHandle
{
    public function release(): void;
}
