<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Apply;

use LaravelCloudBlueprint\Apply\Contract\Delay;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudDatabaseClusterDeletionClient;
use LaravelCloudBlueprint\Cloud\Exception\CloudException;
use LaravelCloudBlueprint\Cloud\Exception\CloudResourceNotFoundException;

final readonly class DatabaseClusterDeletionVerification
{
    public function __construct(
        private Delay $delay = new NativeDelay(),
        private int $attempts = 12,
        private int $delayMilliseconds = 5000,
    ) {
    }

    /** @return 'absent'|'present'|'unknown' */
    public function verifyAbsent(LaravelCloudDatabaseClusterDeletionClient $cloud, string $clusterId): string
    {
        for ($attempt = 1; $attempt <= $this->attempts; $attempt++) {
            try {
                $cloud->databaseCluster($clusterId);
            } catch (CloudResourceNotFoundException) {
                return 'absent';
            } catch (CloudException) {
                return 'unknown';
            }
            if ($attempt < $this->attempts) {
                $this->delay->milliseconds($this->delayMilliseconds);
            }
        }
        return 'present';
    }
}
