<?php

declare(strict_types=1);
namespace LaravelCloudBlueprint\State\Inspection;
final readonly class StateInspectionHumanRenderer
{
    public function render(string $path, LoadedState $loaded, StateInspectionReport $report, bool $cloudRequested, bool $cloudComplete, StateRecoveryReport $recovery, ?StateInspectionCheckResult $check): string
    {
        $lines = ['Laravel Cloud Blueprint State Inspection', '', 'State: ' . $path, 'Schema: V' . $loaded->document->version->value, 'Source schema: V' . $loaded->sourceVersion->value, 'Serial: ' . $loaded->document->serial, 'Cloud verification: ' . ($cloudRequested ? ($cloudComplete ? 'complete' : 'incomplete') : 'not requested'), '', 'Diagnostics:'];
        foreach ($report->diagnostics() as $d) { $lines[] = sprintf('%s %s: %s', $d->severity === DiagnosticSeverity::INFO ? '=' : '!', $d->address === null ? 'state' : (string) $d->address, $d->code->value . ($d->remoteName === null ? '' : ' (' . $d->remoteName . ')')); }
        if ($recovery->guidance() !== []) { $lines[] = ''; $lines[] = 'Recovery:'; foreach ($recovery->guidance() as $g) { $lines[] = '- ' . (string) $g->subjectAddress . ': ' . $g->kind->value; if ($g->commandSuggestion !== null) { $lines[] = '  ' . $g->commandSuggestion->command . ' ' . implode(' ', $g->commandSuggestion->arguments); } if (!$g->restoresDerivedProvenance) { $lines[] = '  Derived provenance is not restored.'; } } }
        $lines[] = ''; $lines[] = 'Summary: ' . count($report->diagnostics()) . ' diagnostic(s).';
        if ($check !== null) { $lines[] = $check->passed() ? 'Check passed.' : sprintf('Check failed: %d diagnostic(s) violate the policy.', $check->failingCount); }
        return implode("\n", $lines);
    }
}
