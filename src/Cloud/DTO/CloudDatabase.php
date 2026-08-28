<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\DTO;

final readonly class CloudDatabase
{
    public function __construct(
        public string $id,
        public string $clusterId,
        public string $name,
    ) {
    }
}
