<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Apply;

use LaravelCloudBlueprint\Apply\Contract\Delay;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudClient;
use LaravelCloudBlueprint\Cloud\Exception\CloudException;

final readonly class EnvironmentDeletionVerification
{
    public function __construct(
        private Delay $delay = new NativeDelay(),
        private int $attempts = 3,
        private int $delayMilliseconds = 1000,
    ) {
    }

    /**
     * @return 'absent'|'present'|'failed'
     */
    public function verifyAbsent(
        LaravelCloudClient $cloud,
        string $applicationRemoteId,
        string $environmentRemoteId,
    ): string {
        $lastObservation = 'failed';

        for ($attempt = 1; $attempt <= $this->attempts; ++$attempt) {
            try {
                $remoteEnvironments = $cloud->environments($applicationRemoteId);
                $found = false;
                foreach ($remoteEnvironments as $remoteEnvironment) {
                    if ($remoteEnvironment->id === $environmentRemoteId) {
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    return 'absent';
                }
                $lastObservation = 'present';
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
