<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\State\Inspection;

final readonly class StateInspectionResult
{
    public function __construct(
        public StateInspectionReport $report,
        public StateInspectionCloudEvidence $cloudEvidence,
        public StateRecoveryReport $recovery,
    ) {
    }
}
