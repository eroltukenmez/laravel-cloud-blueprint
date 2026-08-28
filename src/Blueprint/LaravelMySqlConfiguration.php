<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Blueprint;

final readonly class LaravelMySqlConfiguration implements DatabaseClusterConfiguration
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
