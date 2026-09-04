<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Observation;

use LaravelCloudBlueprint\Cloud\DTO\CloudDatabaseCluster;
use LaravelCloudBlueprint\Cloud\DTO\DatabaseClusterLifecycleReadiness;

final readonly class DatabaseClusterCandidate
{
    public function __construct(
        public CloudDatabaseCluster $cluster,
        public DatabaseClusterLifecycleReadiness $lifecycle,
    ) {
    }
}
