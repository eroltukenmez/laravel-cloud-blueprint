<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Blueprint;

final readonly class SourceDefinition
{
    public function __construct(
        public SourceProvider $provider,
        public string $repository,
    ) {
    }
}
