<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\DTO;

use LaravelCloudBlueprint\Blueprint\SourceProvider;

final readonly class CreateApplicationRequest
{
    public function __construct(
        public string $name,
        public string $repository,
        public string $region,
        public SourceProvider $sourceProvider,
    ) {
    }
}
