<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\DTO;

final readonly class CloudNeonPostgresConfiguration implements CloudDatabaseClusterConfiguration
{
    public function __construct(
        public float $minimumComputeUnits,
        public float $maximumComputeUnits,
        public int $suspendSeconds,
        public int $retentionDays,
    ) {
    }
}
