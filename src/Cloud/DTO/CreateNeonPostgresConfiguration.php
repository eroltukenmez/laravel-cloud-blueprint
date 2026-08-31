<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\DTO;

final readonly class CreateNeonPostgresConfiguration implements DatabaseClusterCreateConfiguration
{
    public function __construct(
        public float $minimumComputeUnits,
        public float $maximumComputeUnits,
        public int $suspendSeconds,
        public int $retentionDays,
    ) {
    }

    public function payload(): array
    {
        return [
            'cu_min' => $this->minimumComputeUnits,
            'cu_max' => $this->maximumComputeUnits,
            'suspend_seconds' => $this->suspendSeconds,
            'retention_days' => $this->retentionDays,
        ];
    }
}
