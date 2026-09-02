<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\DTO;

final readonly class CloudDatabaseSnapshot
{
    public function __construct(
        public string $id,
        public string $clusterId,
        public DatabaseSnapshotType $type,
        public ?DatabaseSnapshotStatus $status,
        public bool $pointInTimeRecoveryEnabled,
    ) {
    }
}
