<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Apply;

use LaravelCloudBlueprint\Apply\Contract\Delay;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudDatabaseClient;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabaseCluster;
use LaravelCloudBlueprint\Cloud\Exception\CloudResponseException;

final readonly class DatabaseClusterReadiness
{
    public function __construct(
        private Delay $delay = new NativeDelay(),
        private int $attempts = 12,
        private int $delayMilliseconds = 5000,
    ) {
    }

    public function wait(LaravelCloudDatabaseClient $cloud, CloudDatabaseCluster $cluster): CloudDatabaseCluster
    {
        if ($cluster->status === 'available') {
            return $cluster;
        }

        $current = $cluster;
        for ($attempt = 1; $attempt <= $this->attempts; ++$attempt) {
            if ($current->status !== 'creating') {
                throw $this->unsafeStatus($current);
            }
            $this->delay->milliseconds($this->delayMilliseconds);
            $current = $cloud->databaseCluster($cluster->id);
            if ($current->status === 'available') {
                return $current;
            }
        }

        throw new CloudResponseException(
            'Database Cluster did not become available within the bounded readiness window; its confirmed identity remains checkpointed.',
            'GET',
            '/databases/clusters/{id}',
        );
    }

    private function unsafeStatus(CloudDatabaseCluster $cluster): CloudResponseException
    {
        return new CloudResponseException(
            'Database Cluster entered a failed, unavailable, or unknown readiness status; child creation was stopped.',
            'GET',
            '/databases/clusters/{id}',
        );
    }
}
