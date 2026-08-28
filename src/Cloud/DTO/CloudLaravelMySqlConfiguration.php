<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\DTO;

final readonly class CloudLaravelMySqlConfiguration implements CloudDatabaseClusterConfiguration
{
    public function __construct(
        public string $size,
        public int $storage,
        public int $retentionDays,
        public bool $usesScheduledSnapshots,
        public bool $isPublic,
    ) {
    }
}
