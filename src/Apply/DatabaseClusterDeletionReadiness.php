<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Apply;

use LaravelCloudBlueprint\Apply\Contract\Delay;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudDatabaseClusterDeletionClient;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabaseCluster;
use LaravelCloudBlueprint\Cloud\Exception\CloudResponseException;

final readonly class DatabaseClusterDeletionReadiness
{
    private const array TRANSITIONAL = ['creating', 'updating', 'restarting', 'upgrading', 'moving', 'restoring', 'snapshotting_before_archiving', 'archiving'];

    public function __construct(
        private Delay $delay = new NativeDelay(),
        private int $attempts = 12,
        private int $delayMilliseconds = 5000,
    ) {
    }

    public function wait(LaravelCloudDatabaseClusterDeletionClient $cloud, CloudDatabaseCluster $cluster): CloudDatabaseCluster
    {
        $current = $cluster;
        for ($attempt = 1; $attempt <= $this->attempts; $attempt++) {
            if ($current->status === 'available') {
                return $current;
            }
            if (!in_array($current->status, self::TRANSITIONAL, true)) {
                throw new CloudResponseException('Database Cluster lifecycle is not eligible for guarded deletion.', 'GET', '/databases/clusters/{id}');
            }
            if ($attempt < $this->attempts) {
                $this->delay->milliseconds($this->delayMilliseconds);
                $current = $cloud->databaseCluster($cluster->id);
            }
        }
        throw new CloudResponseException('Database Cluster did not become deletion-eligible within the bounded observation window.', 'GET', '/databases/clusters/{id}');
    }
}
