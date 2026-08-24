<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\DTO;

final readonly class CloudEnvironmentVariable
{
    public function __construct(
        public string $key,
        public string $value,
    ) {
    }
}
