<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Observation;

use InvalidArgumentException;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabase;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\State\StateOwnershipClassification;
use LaravelCloudBlueprint\State\StateProvenance;
use LaravelCloudBlueprint\State\StateResource;

final readonly class DerivedDatabaseObservationFactory
{
    public function create(
        StateResource $derived,
        StateResource $parent,
        DerivedDatabaseObservationEvidence $evidence,
    ): ResourceObservation {
        $this->requireDerivedAuthorization($derived);
        if ($parent->type !== ResourceType::DATABASE_CLUSTER
            || $parent->isDerived()
            || $derived->parent === null
            || (string) $derived->parent !== (string) $parent->address) {
            return $this->result($derived, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::CONFLICT, ReconciliationStatus::UNSUPPORTED);
        }
        if ($evidence->ownershipConflict) {
            return $this->result($derived, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::CONFLICT, ReconciliationStatus::UNSUPPORTED);
        }
        if ($evidence->blueprintAddressCollision) {
            return $this->result($derived, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::DERIVED, ReconciliationStatus::UNSUPPORTED);
        }

        $exact = array_values(array_filter(
            $evidence->databases(),
            static fn (CloudDatabase $database): bool => $database->id === $derived->remoteId,
        ));
        if (count($exact) > 1) {
            return $this->result($derived, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::CONFLICT, ReconciliationStatus::UNSUPPORTED);
        }
        if ($exact !== [] && ($exact[0]->clusterId !== $parent->remoteId
            || ($exact[0]->relationshipClusterId !== null
                && $exact[0]->relationshipClusterId !== $parent->remoteId))) {
            return $this->result($derived, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::DERIVED, ReconciliationStatus::UNSUPPORTED);
        }
        if ($evidence->relationshipConflict) {
            return $this->result($derived, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::DERIVED, ReconciliationStatus::UNSUPPORTED);
        }
        if ($evidence->listCompleteness === EvidenceStatus::INCOMPLETE
            || $evidence->relationshipCompleteness === EvidenceStatus::INCOMPLETE) {
            return $this->unknown($derived);
        }

        $relationshipMatches = array_values(array_filter(
            $evidence->relationshipDatabaseIds,
            static fn (string $id): bool => $id === $derived->remoteId,
        ));
        if ($exact === []) {
            return $this->result(
                $derived,
                $evidence->replacementCandidates() === []
                    ? ObservationKind::IDENTITY_MISSING
                    : ObservationKind::IDENTITY_REPLACEMENT,
                OwnershipStatus::DERIVED,
                ReconciliationStatus::UNSUPPORTED,
            );
        }
        if (count($relationshipMatches) !== 1) {
            return $this->result($derived, ObservationKind::IDENTITY_CONFLICT, OwnershipStatus::DERIVED, ReconciliationStatus::UNSUPPORTED);
        }

        return $this->result($derived, ObservationKind::IN_SYNC, OwnershipStatus::DERIVED, ReconciliationStatus::NOT_APPLICABLE);
    }

    private function requireDerivedAuthorization(StateResource $derived): void
    {
        if ($derived->type !== ResourceType::DATABASE
            || $derived->classification !== StateOwnershipClassification::DERIVED
            || $derived->provenance !== StateProvenance::CLUSTER_CREATE_RESPONSE) {
            throw new InvalidArgumentException('Derived Database observation requires valid State provenance authorization.');
        }
    }

    private function unknown(StateResource $derived): ResourceObservation
    {
        return $this->result(
            $derived,
            ObservationKind::UNKNOWN,
            OwnershipStatus::DERIVED,
            ReconciliationStatus::BLOCKED,
            EvidenceStatus::INCOMPLETE,
        );
    }

    private function result(
        StateResource $derived,
        ObservationKind $kind,
        OwnershipStatus $ownership,
        ReconciliationStatus $reconciliation,
        EvidenceStatus $evidence = EvidenceStatus::COMPLETE,
    ): ResourceObservation {
        return new ResourceObservation($derived->address, $kind, $ownership, $reconciliation, $evidence);
    }
}
