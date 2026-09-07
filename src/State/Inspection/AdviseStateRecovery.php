<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\State\Inspection;

use LaravelCloudBlueprint\Application\BlueprintLoadResult;
use LaravelCloudBlueprint\Application\Import\CreateImportProposal;
use LaravelCloudBlueprint\Application\Import\ImportCandidate;
use LaravelCloudBlueprint\Application\Import\ImportProposal;
use LaravelCloudBlueprint\Application\Import\ImportStatus;
use LaravelCloudBlueprint\Application\State\StateOwnershipReleaseProposal;
use LaravelCloudBlueprint\Blueprint\Blueprint;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\State\StateDocument;

final readonly class AdviseStateRecovery
{
    public function __construct(private CreateImportProposal $imports = new CreateImportProposal())
    {
    }

    public function advise(
        StateInspectionReport $inspection,
        StateDocument $state,
        BlueprintLoadResult $loadedBlueprint,
        StateInspectionCloudEvidence $cloudEvidence,
        string $blueprintPath,
    ): StateRecoveryReport {
        if (!$loadedBlueprint->isValid()) {
            return new StateRecoveryReport(BlueprintRecoveryStatus::INVALID);
        }

        $blueprint = $loadedBlueprint->blueprint();
        $proposal = $cloudEvidence->complete ? $this->imports->create(
            $blueprint, $state, $cloudEvidence->applications, $cloudEvidence->environments,
            $cloudEvidence->databaseClusters, $cloudEvidence->databasesByCluster,
        ) : null;
        $guidance = [];
        foreach ($inspection->diagnostics() as $diagnostic) {
            if ($diagnostic->code === StateDiagnosticCode::UNMANAGED_REMOTE_CHILD
                && $diagnostic->address !== null && $diagnostic->remoteName !== null) {
                $this->adviseUnmanagedChild($guidance, $diagnostic->address, $diagnostic->remoteName,
                    $blueprint, $this->candidates($proposal), $cloudEvidence->complete, $blueprintPath);
            }
            if (in_array($diagnostic->code, [StateDiagnosticCode::REMOTE_IDENTITY_MISSING, StateDiagnosticCode::REMOTE_IDENTITY_REPLACEMENT], true)
                && $diagnostic->address !== null) {
                $this->adviseRelease($guidance, $diagnostic->address, $state);
            }
        }

        return new StateRecoveryReport(BlueprintRecoveryStatus::AVAILABLE, ...$guidance);
    }

    /**
     * @param list<StateRecoveryGuidance> $guidance
     * @param list<ImportCandidate> $candidates
     */
    private function adviseUnmanagedChild(array &$guidance, ResourceAddress $clusterAddress, string $remoteName, Blueprint $blueprint, array $candidates, bool $complete, string $blueprintPath): void
    {
        $address = new ResourceAddress(ResourceType::DATABASE, $clusterAddress->name . '.' . $remoteName);
        if ($this->declaresDatabase($blueprint, $clusterAddress, $remoteName)) {
            $candidate = $this->candidate($candidates, $address);
            if ($complete && $candidate?->status === ImportStatus::IMPORTABLE) {
                $guidance[] = new StateRecoveryGuidance($address, RecoveryDisposition::EXPLICIT_IMPORT_AVAILABLE,
                    RecoveryGuidanceKind::IMPORT, false, new RecoveryCommandSuggestion('lcb import', ['--file=' . $blueprintPath]));
            }
            return;
        }
        $guidance[] = new StateRecoveryGuidance($address, RecoveryDisposition::MANUAL_ADOPTION_RUNBOOK,
            RecoveryGuidanceKind::ADOPTION_RUNBOOK, false, null, $remoteName);
    }

    /** @param list<StateRecoveryGuidance> $guidance */
    private function adviseRelease(array &$guidance, ResourceAddress $address, StateDocument $state): void
    {
        $proposal = new StateOwnershipReleaseProposal($address, $state->find($address), ...$state->childrenOf($address));
        if (!$proposal->canRelease()) {
            return;
        }
        $guidance[] = new StateRecoveryGuidance($address, RecoveryDisposition::MANUAL_DECISION_REQUIRED,
            RecoveryGuidanceKind::UNMANAGE, false, new RecoveryCommandSuggestion('lcb state:unmanage', [(string) $address]),
            losesDerivedAuthorization: $proposal->resource?->isDerived() ?? false);
    }

    /** @param list<ImportCandidate> $candidates */
    private function candidate(array $candidates, ResourceAddress $address): ?ImportCandidate
    {
        foreach ($candidates as $candidate) {
            if ((string) $candidate->address === (string) $address) {
                return $candidate;
            }
        }
        return null;
    }

    /** @return list<ImportCandidate> */
    private function candidates(?ImportProposal $proposal): array
    {
        return $proposal === null ? [] : iterator_to_array($proposal, false);
    }

    private function declaresDatabase(Blueprint $blueprint, ResourceAddress $clusterAddress, string $name): bool
    {
        foreach ($blueprint->databaseClusters as $cluster) {
            if ($cluster->name !== $clusterAddress->name) {
                continue;
            }
            foreach ($cluster->databases as $database) {
                if ($database->name === $name) {
                    return true;
                }
            }
        }
        return false;
    }
}
