<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Blueprint;

final readonly class LiteralVariableValue implements VariableValueSource
{
    public function __construct(public string $value)
    {
    }
}
