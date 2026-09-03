<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Drift;

use LaravelCloudBlueprint\Observation\ResourceObservationCollection;

final readonly class DriftReport
{
    public DriftSummary $summary;

    public function __construct(public ResourceObservationCollection $observations)
    {
        $this->summary = new DriftSummary($observations);
    }
}
