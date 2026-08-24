<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Blueprint\Encoder;

use LaravelCloudBlueprint\Blueprint\Blueprint;

interface BlueprintEncoder
{
    public function encode(Blueprint $blueprint): string;
}
