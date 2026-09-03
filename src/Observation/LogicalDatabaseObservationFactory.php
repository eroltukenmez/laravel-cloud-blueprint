<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Observation;

use LaravelCloudBlueprint\Blueprint\LogicalDatabaseDefinition;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabase;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\State\StateResource;

final readonly class LogicalDatabaseObservationFactory
{
    public function create(
        string $clusterName,
        LogicalDatabaseDefinition $desired,
        ?StateResource $managed,
        LogicalDatabaseObservationEvidence $evidence,
    ): ResourceObservation {
        $address = new ResourceAddress(ResourceType::DATABASE, $clusterName . '.' . $desired->name);
        if ($managed?->isDerived() === true
            || $evidence->ownershipConflict
            || ($managed !== null && !$this->validState($managed, $address, $evidence->parent->address))) {
            return $this->result($address, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::CONFLICT, ReconciliationStatus::UNSUPPORTED);
        }
        if ($evidence->parent->completeness === EvidenceStatus::INCOMPLETE
            || $evidence->completeness === EvidenceStatus::INCOMPLETE) {
            return $this->unknown($address, $managed === null ? OwnershipStatus::UNKNOWN : OwnershipStatus::MANAGED);
        }
        $parentId = $evidence->parent->remoteId;
        if ($parentId === null) {
            return $this->unknown($address, $managed === null ? OwnershipStatus::UNKNOWN : OwnershipStatus::MANAGED);
        }

        $databases = $evidence->databases();
        if ($managed === null) {
            $matches = array_values(array_filter(
                $databases,
                static fn (CloudDatabase $database): bool => $database->clusterId === $parentId
                    && $database->name === $desired->name,
            ));
            if ($matches === []) {
                if (!$evidence->parent->authorizesChildren()) {
                    return $this->unknown($address, OwnershipStatus::NONE);
                }
                return $this->result($address, ObservationKind::DESIRED_RESOURCE_MISSING, OwnershipStatus::NONE, ReconciliationStatus::SUPPORTED);
            }
            if (count($matches) !== 1) {
                return $this->result($address, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::UNMANAGED, ReconciliationStatus::BLOCKED);
            }
            return $this->present($address, $matches[0], $parentId, OwnershipStatus::UNMANAGED, $evidence->observeRelationships);
        }

        $exact = array_values(array_filter(
            $databases,
            static fn (CloudDatabase $database): bool => $database->id === $managed->remoteId,
        ));
        if (count($exact) > 1) {
            return $this->result($address, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::CONFLICT, ReconciliationStatus::UNSUPPORTED);
        }
        if ($exact === []) {
            $replacement = array_filter(
                $databases,
                static fn (CloudDatabase $database): bool => $database->clusterId === $parentId
                    && $database->name === $desired->name,
            );
            return $this->result(
                $address,
                $replacement === [] ? ObservationKind::IDENTITY_MISSING : ObservationKind::IDENTITY_REPLACEMENT,
                OwnershipStatus::MANAGED,
                ReconciliationStatus::UNSUPPORTED,
            );
        }
        if ($exact[0]->name !== $desired->name) {
            return $this->result($address, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::MANAGED, ReconciliationStatus::UNSUPPORTED);
        }
        return $this->present($address, $exact[0], $parentId, OwnershipStatus::MANAGED, $evidence->observeRelationships);
    }

    private function validState(
        StateResource $state,
        ResourceAddress $address,
        ?ResourceAddress $parentAddress,
    ): bool {
        return $state->type === ResourceType::DATABASE
            && !$state->isDerived()
            && $state->parent !== null
            && $state->parent->type === ResourceType::DATABASE_CLUSTER
            && ($parentAddress === null || (string) $state->parent === (string) $parentAddress)
            && (string) $state->address === (string) $address;
    }

    private function present(
        ResourceAddress $address,
        CloudDatabase $remote,
        string $parentId,
        OwnershipStatus $ownership,
        bool $observeRelationships,
    ): ResourceObservation {
        if ($remote->clusterId !== $parentId
            || ($remote->relationshipClusterId !== null && $remote->relationshipClusterId !== $parentId)) {
            return $this->result($address, ObservationKind::IDENTITY_CONFLICT, $ownership, ReconciliationStatus::UNSUPPORTED);
        }
        if (!$observeRelationships) {
            return $this->result($address, ObservationKind::IN_SYNC, $ownership, ReconciliationStatus::NOT_APPLICABLE);
        }
        if (!$remote->destructiveRelationshipsComplete
            || $remote->missingRelationships !== []
            || $remote->unknownRelationships !== []) {
            return $this->unknown($address, $ownership);
        }
        if ($remote->environmentIds !== []) {
            return $this->result($address, ObservationKind::LIFECYCLE_CONDITION, $ownership, ReconciliationStatus::BLOCKED);
        }
        return $this->result($address, ObservationKind::IN_SYNC, $ownership, ReconciliationStatus::NOT_APPLICABLE);
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
    ): ResourceObservation {
        return new ResourceObservation($address, $kind, $ownership, $reconciliation, $evidence);
    }
}
