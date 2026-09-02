<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Apply;

use LaravelCloudBlueprint\Apply\Contract\Delay;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudDatabaseClient;
use LaravelCloudBlueprint\Cloud\Exception\CloudException;
use LaravelCloudBlueprint\Cloud\Exception\CloudResourceNotFoundException;

final readonly class DatabaseDeletionVerification
{
    public function __construct(
        private Delay $delay = new NativeDelay(),
        private int $attempts = 3,
        private int $delayMilliseconds = 1000,
    ) {
    }

    /** @return 'absent'|'present'|'failed' */
    public function verifyAbsent(
        LaravelCloudDatabaseClient $cloud,
        string $clusterRemoteId,
        string $databaseRemoteId,
    ): string {
        $lastObservation = 'failed';

        for ($attempt = 1; $attempt <= $this->attempts; ++$attempt) {
            try {
                $cloud->database($clusterRemoteId, $databaseRemoteId);
                $lastObservation = 'present';
            } catch (CloudResourceNotFoundException) {
                return 'absent';
            } catch (CloudException) {
                $lastObservation = 'failed';
            }

            if ($attempt < $this->attempts) {
                $this->delay->milliseconds($this->delayMilliseconds);
            }
        }

        return $lastObservation;
    }
}
