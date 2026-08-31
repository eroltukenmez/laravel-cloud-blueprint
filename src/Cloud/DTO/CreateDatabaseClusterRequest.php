<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\DTO;

final readonly class CreateDatabaseClusterRequest
{
    public function __construct(
        public string $name,
        public string $type,
        public string $region,
        public DatabaseClusterCreateConfiguration $configuration,
    ) {
    }
}
