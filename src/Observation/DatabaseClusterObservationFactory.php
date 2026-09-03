<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Observation;

use LaravelCloudBlueprint\Blueprint\DatabaseClusterDefinition;
use LaravelCloudBlueprint\Blueprint\LaravelMySqlConfiguration;
use LaravelCloudBlueprint\Blueprint\NeonPostgresConfiguration;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabaseCluster;
use LaravelCloudBlueprint\Cloud\DTO\CloudLaravelMySqlConfiguration;
use LaravelCloudBlueprint\Cloud\DTO\CloudNeonPostgresConfiguration;
use LaravelCloudBlueprint\Cloud\DTO\DatabaseClusterLifecycleReadiness;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\State\StateResource;

final readonly class DatabaseClusterObservationFactory
{
    public function create(
        DatabaseClusterDefinition $desired,
        ?StateResource $managed,
        DatabaseClusterObservationEvidence $evidence,
    ): ResourceObservation {
        $address = new ResourceAddress(ResourceType::DATABASE_CLUSTER, $desired->name);
        if ($evidence->ownershipConflict || ($managed !== null && !$this->validState($managed, $address))) {
            return $this->result($address, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::CONFLICT, ReconciliationStatus::UNSUPPORTED);
        }
        if ($evidence->completeness === EvidenceStatus::INCOMPLETE) {
            return $this->unknown($address, $managed === null ? OwnershipStatus::UNKNOWN : OwnershipStatus::MANAGED);
        }

        $candidates = $evidence->candidates();
        if ($managed === null) {
            $matches = array_values(array_filter(
                $candidates,
                static fn (DatabaseClusterCandidate $candidate): bool => $candidate->cluster->name === $desired->name,
            ));
            if ($matches === []) {
                return $this->result($address, ObservationKind::DESIRED_RESOURCE_MISSING, OwnershipStatus::NONE, ReconciliationStatus::SUPPORTED);
            }
            if (count($matches) !== 1) {
                return $this->result($address, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::UNMANAGED, ReconciliationStatus::BLOCKED);
            }
            return $this->compare($address, $desired, $matches[0], OwnershipStatus::UNMANAGED);
        }

        $exact = array_values(array_filter(
            $candidates,
            static fn (DatabaseClusterCandidate $candidate): bool => $candidate->cluster->id === $managed->remoteId,
        ));
        if (count($exact) > 1) {
            return $this->result($address, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::CONFLICT, ReconciliationStatus::UNSUPPORTED);
        }
        if ($exact === []) {
            $replacement = array_filter(
                $candidates,
                static fn (DatabaseClusterCandidate $candidate): bool => $candidate->cluster->name === $desired->name,
            );
            return $this->result(
                $address,
                $replacement === [] ? ObservationKind::IDENTITY_MISSING : ObservationKind::IDENTITY_REPLACEMENT,
                OwnershipStatus::MANAGED,
                ReconciliationStatus::UNSUPPORTED,
            );
        }
        if ($exact[0]->cluster->name !== $desired->name) {
            return $this->result($address, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::MANAGED, ReconciliationStatus::UNSUPPORTED);
        }
        return $this->compare($address, $desired, $exact[0], OwnershipStatus::MANAGED);
    }

    private function validState(StateResource $state, ResourceAddress $address): bool
    {
        return $state->type === ResourceType::DATABASE_CLUSTER
            && $state->parent === null
            && !$state->isDerived()
            && (string) $state->address === (string) $address;
    }

    private function compare(
        ResourceAddress $address,
        DatabaseClusterDefinition $desired,
        DatabaseClusterCandidate $candidate,
        OwnershipStatus $ownership,
    ): ResourceObservation {
        if ($candidate->lifecycle === DatabaseClusterLifecycleReadiness::UNKNOWN) {
            return $this->unknown($address, $ownership);
        }
        if ($candidate->lifecycle === DatabaseClusterLifecycleReadiness::INELIGIBLE) {
            return $this->result($address, ObservationKind::LIFECYCLE_CONDITION, $ownership, ReconciliationStatus::BLOCKED);
        }

        $remote = $candidate->cluster;
        $fields = [];
        if ($remote->type !== $desired->type->value) {
            $fields[] = 'type';
        }
        if ($remote->region !== $desired->region) {
            $fields[] = 'region';
        }
        if ($fields !== []) {
            return $this->configurationDifference($address, $ownership, $fields);
        }
        $configuration = $this->configurationFields($desired, $remote);
        if ($configuration === null) {
            return $this->unknown($address, $ownership);
        }
        if ($configuration !== []) {
            return $this->configurationDifference($address, $ownership, $configuration);
        }
        return $this->result($address, ObservationKind::IN_SYNC, $ownership, ReconciliationStatus::NOT_APPLICABLE);
    }

    /** @param list<string> $fields */
    private function configurationDifference(
        ResourceAddress $address,
        OwnershipStatus $ownership,
        array $fields,
    ): ResourceObservation {
        return $this->result(
            $address,
            ObservationKind::CONFIGURATION_DIFFERENCE,
            $ownership,
            $ownership === OwnershipStatus::MANAGED ? ReconciliationStatus::UNSUPPORTED : ReconciliationStatus::BLOCKED,
            EvidenceStatus::COMPLETE,
            new ChangedFields(...$fields),
        );
    }

    /** @return list<string>|null */
    private function configurationFields(DatabaseClusterDefinition $desired, CloudDatabaseCluster $remote): ?array
    {
        if ($desired->configuration instanceof LaravelMySqlConfiguration) {
            if (!$remote->configuration instanceof CloudLaravelMySqlConfiguration) {
                return null;
            }
            return $this->differentFields([
                'size' => [$remote->configuration->size, $desired->configuration->size],
                'storage' => [$remote->configuration->storage, $desired->configuration->storage],
                'retention_days' => [$remote->configuration->retentionDays, $desired->configuration->retentionDays],
                'uses_scheduled_snapshots' => [$remote->configuration->usesScheduledSnapshots, $desired->configuration->usesScheduledSnapshots],
                'is_public' => [$remote->configuration->isPublic, $desired->configuration->isPublic],
            ]);
        }
        if ($desired->configuration instanceof NeonPostgresConfiguration) {
            if (!$remote->configuration instanceof CloudNeonPostgresConfiguration) {
                return null;
            }
            return $this->differentFields([
                'cu_min' => [$remote->configuration->minimumComputeUnits, $desired->configuration->minimumComputeUnits],
                'cu_max' => [$remote->configuration->maximumComputeUnits, $desired->configuration->maximumComputeUnits],
                'suspend_seconds' => [$remote->configuration->suspendSeconds, $desired->configuration->suspendSeconds],
                'retention_days' => [$remote->configuration->retentionDays, $desired->configuration->retentionDays],
            ]);
        }
        return null;
    }

    /**
     * @param array<string, array{string|int|float|bool, string|int|float|bool}> $values
     * @return list<string>
     */
    private function differentFields(array $values): array
    {
        $fields = [];
        foreach ($values as $field => [$remote, $desired]) {
            if ($remote !== $desired && !(is_float($remote) && is_float($desired) && $remote == $desired)) {
                $fields[] = $field;
            }
        }
        return $fields;
    }

    private function unknown(ResourceAddress $address, OwnershipStatus $ownership): ResourceObservation
    {
        return $this->result($address, ObservationKind::UNKNOWN, $ownership, ReconciliationStatus::BLOCKED, EvidenceStatus::INCOMPLETE);
    }

    private function result(
        ResourceAddress $address,
        ObservationKind $kind,
        OwnershipStatus $ownership,
        ReconciliationStatus $reconciliation,
        EvidenceStatus $evidence = EvidenceStatus::COMPLETE,
        ?ChangedFields $fields = null,
    ): ResourceObservation {
        return new ResourceObservation($address, $kind, $ownership, $reconciliation, $evidence, $fields);
    }
}
