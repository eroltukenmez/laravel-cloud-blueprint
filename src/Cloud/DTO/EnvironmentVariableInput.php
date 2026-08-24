<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\DTO;

final readonly class EnvironmentVariableInput
{
    public function __construct(
        public string $key,
        public string $value,
    ) {
    }
}
