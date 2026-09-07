<?php

declare(strict_types=1);
namespace LaravelCloudBlueprint\State\Inspection;
final readonly class StateInspectionHumanRenderer
{
    public function render(string $path, LoadedState $loaded, StateInspectionReport $report, bool $cloudRequested, bool $cloudComplete, StateRecoveryReport $recovery, ?StateInspectionCheckResult $check): string
    {
        $lines = ['Laravel Cloud Blueprint State Inspection', '', 'State: ' . $path, 'Schema: V' . $loaded->document->version->value, 'Source schema: V' . $loaded->sourceVersion->value, 'Serial: ' . $loaded->document->serial, 'Cloud verification: ' . ($cloudRequested ? ($cloudComplete ? 'complete' : 'incomplete') : 'not requested'), '', 'Diagnostics:'];
        foreach ($report->diagnostics() as $d) { $lines[] = sprintf('%s %s: %s', $d->severity === DiagnosticSeverity::INFO ? '=' : '!', $d->address === null ? 'state' : (string) $d->address, $d->code->value . ($d->remoteName === null ? '' : ' (' . $d->remoteName . ')')); }
        if ($recovery->guidance() !== []) {
            $lines[] = '';
            $lines[] = 'Recovery:';
            foreach ($recovery->guidance() as $g) {
                $lines[] = '- ' . (string) $g->subjectAddress . ': ' . $g->kind->value;
                if ($g->disposition === RecoveryDisposition::MANUAL_ADOPTION_RUNBOOK) {
                    $lines[] = '  Temporarily declare ' . (string) $g->subjectAddress . ' as an ordinary Database in the Blueprint.';
                    $lines[] = '  Review and run the normal explicit import workflow after declaration.';
                    $lines[] = '  If adoption succeeds, LCB ownership becomes MANAGED.';
                    $lines[] = '  Historical origin cannot be reconstructed from current Cloud topology.';
                    $lines[] = '  Import does not restore DERIVED / CLUSTER_CREATE_RESPONSE provenance.';
                    $lines[] = '  If deletion is intended afterward, remove the temporary declaration, review the resulting plan, and use the normal guarded apply workflow.';
                    continue;
                }
                if ($g->commandSuggestion !== null) {
                    $lines[] = '  ' . $g->commandSuggestion->command . ' ' . implode(' ', $g->commandSuggestion->arguments);
                }
                if ($g->disposition === RecoveryDisposition::EXPLICIT_IMPORT_AVAILABLE) {
                    $lines[] = '  Successful import records the resource as MANAGED.';
                }
                if (!$g->restoresDerivedProvenance) {
                    $lines[] = '  Import does not restore DERIVED / CLUSTER_CREATE_RESPONSE provenance.';
                }
            }
        }
        $lines[] = ''; $lines[] = 'Summary: ' . count($report->diagnostics()) . ' diagnostic(s).';
        if ($check !== null) { $lines[] = $check->passed() ? 'Check passed.' : sprintf('Check failed: %d diagnostic(s) violate the policy.', $check->failingCount); }
        return implode("\n", $lines);
    }
}
