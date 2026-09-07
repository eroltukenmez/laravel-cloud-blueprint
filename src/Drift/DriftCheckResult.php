<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Drift;

use LaravelCloudBlueprint\Observation\ResourceObservationCollection;

final readonly class DriftCheckResult
{
    public function __construct(public ResourceObservationCollection $failingObservations)
    {
    }

    public function passed(): bool
    {
        return $this->failingCount() === 0;
    }

    public function failingCount(): int
    {
        return $this->failingObservations->count();
    }
}
