<?php

declare(strict_types=1);
namespace LaravelCloudBlueprint\State\Inspection;
use LaravelCloudBlueprint\State\StateDocument;
final readonly class StateInspectionJsonRenderer
{
    /** @return array<string, mixed> */
    public function render(string $path, LoadedState $loaded, StateInspectionReport $report, bool $cloudRequested, bool $cloudComplete, StateRecoveryReport $recovery): array
    {
        $diagnostics = array_map(static fn (StateDiagnostic $d): array => array_filter(['address' => $d->address === null ? null : (string) $d->address, 'code' => $d->code->value, 'severity' => $d->severity->value, 'evidence' => $d->evidenceSource->value, 'disposition' => $d->recoveryDisposition->value, 'classification' => $d->classification?->value, 'provenance' => $d->provenance?->value, 'remote_name' => $d->remoteName], static fn ($v): bool => $v !== null), $report->diagnostics());
        $guidance = array_map(static fn (StateRecoveryGuidance $g): array => array_filter(['address' => (string) $g->subjectAddress, 'disposition' => $g->disposition->value, 'kind' => $g->kind->value, 'restores_derived_provenance' => $g->restoresDerivedProvenance, 'loses_derived_authorization' => $g->losesDerivedAuthorization, 'command' => $g->commandSuggestion === null ? null : ['name' => $g->commandSuggestion->command, 'arguments' => $g->commandSuggestion->arguments], 'remote_name' => $g->remoteName], static fn ($v): bool => $v !== null), $recovery->guidance());
        $warnings = count(array_filter($report->diagnostics(), static fn (StateDiagnostic $d): bool => $d->severity === DiagnosticSeverity::WARNING));
        $errors = count(array_filter($report->diagnostics(), static fn (StateDiagnostic $d): bool => $d->severity === DiagnosticSeverity::ERROR));
        return ['contract_version' => 1, 'status' => 'success', 'result' => $warnings + $errors === 0 ? 'healthy' : 'attention_required', 'state' => ['path' => $path, 'effective_version' => $loaded->document->version->value, 'source_version' => $loaded->sourceVersion->value, 'serial' => $loaded->document->serial, 'organization' => $loaded->document->organization, 'resource_count' => count($loaded->document->resources())], 'cloud' => ['requested' => $cloudRequested, 'evidence' => $cloudRequested ? ($cloudComplete ? 'complete' : 'incomplete') : 'not_requested'], 'summary' => ['healthy' => count($report->diagnostics()) - $warnings - $errors, 'warnings' => $warnings, 'errors' => $errors, 'incomplete' => count(array_filter($report->diagnostics(), static fn (StateDiagnostic $d): bool => $d->code === StateDiagnosticCode::EVIDENCE_INCOMPLETE)), 'total' => count($report->diagnostics())], 'diagnostics' => $diagnostics, 'recovery_guidance' => $guidance];
    }
}
