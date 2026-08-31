<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Blueprint;

final readonly class NeonPostgresConfiguration implements DatabaseClusterConfiguration
{
    public function __construct(
        public float $minimumComputeUnits,
        public float $maximumComputeUnits,
        public int $suspendSeconds,
        public int $retentionDays,
    ) {
    }
}
