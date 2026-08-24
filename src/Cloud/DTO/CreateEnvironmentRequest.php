<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\DTO;

final readonly class CreateEnvironmentRequest
{
    public function __construct(
        public string $name,
        public string $branch,
    ) {
    }
}
