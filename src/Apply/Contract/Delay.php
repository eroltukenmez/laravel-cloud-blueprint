<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Apply\Contract;

interface Delay
{
    public function milliseconds(int $milliseconds): void;
}
