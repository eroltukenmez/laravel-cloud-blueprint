<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\DTO;

final readonly class CreateLaravelMySqlConfiguration implements DatabaseClusterCreateConfiguration
{
    public function __construct(
        public string $size,
        public int $storage,
        public int $retentionDays,
        public bool $usesScheduledSnapshots,
        public bool $isPublic,
    ) {
    }

    public function payload(): array
    {
        return [
            'size' => $this->size,
            'storage' => $this->storage,
            'retention_days' => $this->retentionDays,
            'uses_scheduled_snapshots' => $this->usesScheduledSnapshots,
            'is_public' => $this->isPublic,
        ];
    }
}
