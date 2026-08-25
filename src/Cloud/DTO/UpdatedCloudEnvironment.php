<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\DTO;

final readonly class UpdatedCloudEnvironment
{
    public function __construct(
        public string $id,
        public string $name,
        public string $branch,
    ) {
    }
}
