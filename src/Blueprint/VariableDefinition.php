<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Blueprint;

final readonly class VariableDefinition
{
    public function __construct(
        public string $name,
        public VariableValueSource $valueSource,
        public bool $sensitive,
    ) {
    }
}
