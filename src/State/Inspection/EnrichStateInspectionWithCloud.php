<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\State\Inspection;

use LaravelCloudBlueprint\Cloud\Contract\StateInspectionCloudReader;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabase;
use LaravelCloudBlueprint\Cloud\Exception\CloudException;
use LaravelCloudBlueprint\Cloud\Exception\CloudResourceNotFoundException;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\State\StateDocument;
use LaravelCloudBlueprint\State\StateResource;

final readonly class EnrichStateInspectionWithCloud
{
    public function enrich(
        StateInspectionReport $local,
        StateDocument $state,
        StateInspectionCloudReader $cloud,
    ): StateInspectionReport {
        $diagnostics = $local->diagnostics();
        $this->applications($state, $cloud, $diagnostics);
        foreach ($state->resources() as $resource) {
            match ($resource->type) {
                ResourceType::ENVIRONMENT => $this->environment($resource, $state, $cloud, $diagnostics),
                ResourceType::DATABASE_CLUSTER => $this->cluster($resource, $state, $cloud, $diagnostics),
                ResourceType::DATABASE => $this->database($resource, $state, $cloud, $diagnostics),
                default => null,
            };
        }

        return new StateInspectionReport(...$diagnostics);
    }

    /** @param list<StateDiagnostic> $diagnostics */
    private function applications(StateDocument $state, StateInspectionCloudReader $cloud, array &$diagnostics): void
    {
        $owned = array_values(array_filter($state->resources(), static fn (StateResource $resource): bool => $resource->type === ResourceType::APPLICATION));
        if ($owned === []) {
            return;
        }
        try {
            $applications = $cloud->applications();
        } catch (CloudException) {
            foreach ($owned as $resource) {
                $this->incomplete($diagnostics, $resource);
            }
            return;
        }
        foreach ($owned as $resource) {
            $exact = array_values(array_filter($applications, static fn ($remote): bool => $remote->id === $resource->remoteId));
            if (count($exact) === 1) {
                $this->verified($diagnostics, $resource);
            } elseif (count($exact) > 1) {
                $this->conflict($diagnostics, $resource);
            } elseif (array_filter($applications, static fn ($remote): bool => $remote->name === $resource->address->name) !== []) {
                $this->replacement($diagnostics, $resource);
            } else {
                $this->missing($diagnostics, $resource);
            }
        }
    }

    /** @param list<StateDiagnostic> $diagnostics */
    private function environment(StateResource $resource, StateDocument $state, StateInspectionCloudReader $cloud, array &$diagnostics): void
    {
        $parent = $resource->parent === null ? null : $state->find($resource->parent);
        if ($parent === null) {
            $this->incomplete($diagnostics, $resource);
            return;
        }
        try {
            $environments = $cloud->environments($parent->remoteId);
        } catch (CloudException) {
            $this->incomplete($diagnostics, $resource);
            return;
        }
        $exact = array_values(array_filter($environments, static fn ($remote): bool => $remote->id === $resource->remoteId));
        if (count($exact) === 1) {
            if ($exact[0]->applicationId !== $parent->remoteId) {
                $this->parentConflict($diagnostics, $resource);
            } else {
                $this->verified($diagnostics, $resource);
            }
        } elseif (count($exact) > 1) {
            $this->conflict($diagnostics, $resource);
        } elseif (array_filter($environments, static fn ($remote): bool => $remote->name === $resource->address->name) !== []) {
            $this->replacement($diagnostics, $resource);
        } else {
            $this->missing($diagnostics, $resource);
        }
    }

    /** @param list<StateDiagnostic> $diagnostics */
    private function cluster(StateResource $resource, StateDocument $state, StateInspectionCloudReader $cloud, array &$diagnostics): void
    {
        try {
            $cluster = $cloud->databaseCluster($resource->remoteId);
        } catch (CloudResourceNotFoundException) {
            try {
                $replacement = array_filter($cloud->databaseClusters(), static fn ($remote): bool => $remote->name === $resource->address->name);
                $replacement === [] ? $this->missing($diagnostics, $resource) : $this->replacement($diagnostics, $resource);
            } catch (CloudException) {
                $this->incomplete($diagnostics, $resource);
            }
            return;
        } catch (CloudException) {
            $this->incomplete($diagnostics, $resource);
            return;
        }
        if ($cluster->id !== $resource->remoteId) {
            $this->replacement($diagnostics, $resource);
            return;
        }
        $this->verified($diagnostics, $resource);
        if (!$cluster->childDiscoveryComplete || $cluster->missingRelationships !== [] || $cluster->unknownRelationships !== []) {
            $this->incomplete($diagnostics, $resource);
            return;
        }
        try {
            $listed = $cloud->databases($resource->remoteId);
        } catch (CloudException) {
            $this->incomplete($diagnostics, $resource);
            return;
        }
        $listedById = $this->uniqueById($listed);
        $relationshipIds = array_fill_keys($cluster->databaseIds, true);
        if (count($listedById) !== count($listed) || count($relationshipIds) !== count($cluster->databaseIds)
            || array_keys($listedById) !== array_keys($relationshipIds)) {
            $this->incomplete($diagnostics, $resource);
            return;
        }
        $owned = [];
        foreach ($state->childrenOf($resource->address) as $child) {
            if ($child->type === ResourceType::DATABASE) {
                $owned[$child->remoteId] = $child;
            }
        }
        foreach ($listedById as $id => $database) {
            if ($database->clusterId !== $resource->remoteId
                || ($database->relationshipClusterId !== null && $database->relationshipClusterId !== $resource->remoteId)) {
                $this->incomplete($diagnostics, $resource);
                return;
            }
            if (!isset($owned[$id])) {
                $diagnostics[] = new StateDiagnostic(DiagnosticSeverity::WARNING, StateDiagnosticCode::UNMANAGED_REMOTE_CHILD,
                    DiagnosticEvidenceSource::CLOUD, RecoveryDisposition::MANUAL_DECISION_REQUIRED, $resource->address,
                    remoteName: $database->name);
            }
        }
    }

    /** @param list<StateDiagnostic> $diagnostics */
    private function database(StateResource $resource, StateDocument $state, StateInspectionCloudReader $cloud, array &$diagnostics): void
    {
        $parent = $resource->parent === null ? null : $state->find($resource->parent);
        if ($parent === null) {
            $this->incomplete($diagnostics, $resource);
            return;
        }
        try {
            $database = $cloud->database($parent->remoteId, $resource->remoteId);
        } catch (CloudResourceNotFoundException) {
            try {
                $replacement = array_filter($cloud->databases($parent->remoteId),
                    static fn (CloudDatabase $remote): bool => $remote->name === $resource->address->name);
                $replacement === [] ? $this->missing($diagnostics, $resource) : $this->replacement($diagnostics, $resource);
            } catch (CloudException) {
                $this->incomplete($diagnostics, $resource);
            }
            return;
        } catch (CloudException) {
            $this->incomplete($diagnostics, $resource);
            return;
        }
        if ($database->id !== $resource->remoteId) {
            $this->replacement($diagnostics, $resource);
        } elseif ($database->clusterId !== $parent->remoteId
            || ($database->relationshipClusterId !== null && $database->relationshipClusterId !== $parent->remoteId)) {
            $this->parentConflict($diagnostics, $resource);
        } else {
            $this->verified($diagnostics, $resource);
        }
    }

    /**
     * @param list<CloudDatabase> $databases
     * @return array<string, CloudDatabase>
     */
    private function uniqueById(array $databases): array
    {
        $indexed = [];
        foreach ($databases as $database) {
            $indexed[$database->id] = $database;
        }
        ksort($indexed, SORT_STRING);
        return $indexed;
    }

    /** @param list<StateDiagnostic> $diagnostics */
    private function verified(array &$diagnostics, StateResource $resource): void { $this->add($diagnostics, DiagnosticSeverity::INFO, StateDiagnosticCode::REMOTE_OWNERSHIP_VERIFIED, RecoveryDisposition::NONE, $resource); }
    /** @param list<StateDiagnostic> $diagnostics */
    private function missing(array &$diagnostics, StateResource $resource): void { $this->add($diagnostics, DiagnosticSeverity::ERROR, StateDiagnosticCode::REMOTE_IDENTITY_MISSING, RecoveryDisposition::MANUAL_DECISION_REQUIRED, $resource); }
    /** @param list<StateDiagnostic> $diagnostics */
    private function replacement(array &$diagnostics, StateResource $resource): void { $this->add($diagnostics, DiagnosticSeverity::WARNING, StateDiagnosticCode::REMOTE_IDENTITY_REPLACEMENT, RecoveryDisposition::MANUAL_DECISION_REQUIRED, $resource); }
    /** @param list<StateDiagnostic> $diagnostics */
    private function conflict(array &$diagnostics, StateResource $resource): void { $this->add($diagnostics, DiagnosticSeverity::ERROR, StateDiagnosticCode::IDENTITY_CONFLICT, RecoveryDisposition::MANUAL_DECISION_REQUIRED, $resource); }
    /** @param list<StateDiagnostic> $diagnostics */
    private function parentConflict(array &$diagnostics, StateResource $resource): void { $this->add($diagnostics, DiagnosticSeverity::ERROR, StateDiagnosticCode::PARENT_CHILD_IDENTITY_CONFLICT, RecoveryDisposition::MANUAL_DECISION_REQUIRED, $resource); }
    /** @param list<StateDiagnostic> $diagnostics */
    private function incomplete(array &$diagnostics, StateResource $resource): void { $this->add($diagnostics, DiagnosticSeverity::WARNING, StateDiagnosticCode::EVIDENCE_INCOMPLETE, RecoveryDisposition::UNAVAILABLE, $resource); }
    /** @param list<StateDiagnostic> $diagnostics */
    private function add(array &$diagnostics, DiagnosticSeverity $severity, StateDiagnosticCode $code, RecoveryDisposition $disposition, StateResource $resource): void
    { $diagnostics[] = new StateDiagnostic($severity, $code, DiagnosticEvidenceSource::CLOUD, $disposition, $resource->address, $resource->classification, $resource->provenance); }
}
