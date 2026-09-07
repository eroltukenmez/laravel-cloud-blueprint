<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Drift;

use LaravelCloudBlueprint\Observation\EvidenceStatus;
use LaravelCloudBlueprint\Observation\ObservationKind;
use LaravelCloudBlueprint\Observation\ResourceObservation;
use LaravelCloudBlueprint\Observation\ResourceObservationCollection;

final class DriftCheckEvaluator
{
    public function evaluate(DriftReport $report): DriftCheckResult
    {
        $failing = [];
        foreach ($report->observations as $observation) {
            if (!$this->passes($observation)) {
                $failing[] = $observation;
            }
        }

        return new DriftCheckResult(new ResourceObservationCollection(...$failing));
    }

    private function passes(ResourceObservation $observation): bool
    {
        return $observation->observation === ObservationKind::IN_SYNC
            && $observation->evidence === EvidenceStatus::COMPLETE;
    }
}
