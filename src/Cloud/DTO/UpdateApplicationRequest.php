<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\DTO;

use LaravelCloudBlueprint\Blueprint\SourceProvider;

final readonly class UpdateApplicationRequest
{
    public function __construct(
        public string $repository,
        public SourceProvider $sourceProvider,
    ) {
    }
}
