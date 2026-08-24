<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Blueprint;

final readonly class ApplicationDefinition
{
    public function __construct(
        public string $name,
        public SourceDefinition $source,
    ) {
    }
}
