<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Apply;

use LaravelCloudBlueprint\Apply\Contract\Delay;

final readonly class NativeDelay implements Delay
{
    public function milliseconds(int $milliseconds): void
    {
        usleep($milliseconds * 1000);
    }
}
