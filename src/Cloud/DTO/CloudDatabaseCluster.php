<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\DTO;

final readonly class CloudDatabaseCluster
{
    public function __construct(
        public string $id,
        public string $name,
        public string $type,
        public string $status,
        public string $region,
        public CloudDatabaseClusterConfiguration $configuration,
    ) {
    }
}
