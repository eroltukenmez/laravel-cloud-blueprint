<?php

declare(strict_types=1);
namespace LaravelCloudBlueprint\State\Inspection;
final readonly class StateInspectionCheckEvaluator
{
    public function evaluate(StateInspectionReport $report, bool $cloudRequested, bool $cloudComplete): StateInspectionCheckResult
    {
        $failing = count(array_filter($report->diagnostics(), static fn (StateDiagnostic $d): bool => $d->severity !== DiagnosticSeverity::INFO));
        return new StateInspectionCheckResult($failing + ($cloudRequested && !$cloudComplete ? 1 : 0));
    }
}
