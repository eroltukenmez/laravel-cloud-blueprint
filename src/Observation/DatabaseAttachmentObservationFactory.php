<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Observation;

use LaravelCloudBlueprint\Blueprint\DatabaseAttachmentIntent;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironment;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\State\StateResource;

final readonly class DatabaseAttachmentObservationFactory
{
    public function create(
        string $environmentName,
        DatabaseAttachmentIntent $intent,
        ?StateResource $stateEnvironment,
        ?CloudEnvironment $remoteEnvironment,
        ?StateResource $stateDatabase,
        EvidenceStatus $evidence,
        bool $actionable = false,
    ): ?ResourceObservation {
        if ($intent->isUnmanaged()) {
            return null;
        }

        $address = new ResourceAddress(ResourceType::DATABASE_ATTACHMENT, $environmentName);
        if ($stateEnvironment === null) {
            return new ResourceObservation($address, ObservationKind::IDENTITY_MISSING, OwnershipStatus::MANAGED,
                ReconciliationStatus::UNSUPPORTED, EvidenceStatus::COMPLETE, reasonCode: ReasonCode::DATABASE_ATTACHMENT_ENVIRONMENT_IDENTITY_INVALID);
        }
        if ($intent->isAttached() && $stateDatabase === null) {
            return new ResourceObservation($address, ObservationKind::IDENTITY_MISSING, OwnershipStatus::MANAGED,
                ReconciliationStatus::UNSUPPORTED, EvidenceStatus::COMPLETE, reasonCode: ReasonCode::DATABASE_ATTACHMENT_DATABASE_IDENTITY_INVALID);
        }
        if ($remoteEnvironment === null || $evidence === EvidenceStatus::INCOMPLETE) {
            return new ResourceObservation($address, ObservationKind::UNKNOWN, OwnershipStatus::MANAGED,
                ReconciliationStatus::UNSUPPORTED, EvidenceStatus::INCOMPLETE, reasonCode: ReasonCode::DATABASE_ATTACHMENT_RELATIONSHIP_INCOMPLETE);
        }

        $desiredDatabaseId = $stateDatabase?->remoteId;
        $actualDatabaseId = $remoteEnvironment->databaseId;
        $matches = $intent->isDetached()
            ? $actualDatabaseId === null
            : $desiredDatabaseId !== null && $actualDatabaseId === $desiredDatabaseId;

        return new ResourceObservation(
            $address,
            $matches ? ObservationKind::IN_SYNC : ObservationKind::CONFIGURATION_DIFFERENCE,
            OwnershipStatus::MANAGED,
            $actionable ? ReconciliationStatus::SUPPORTED : ReconciliationStatus::UNSUPPORTED,
            EvidenceStatus::COMPLETE,
            $matches ? null : new ChangedFields('database'),
            $matches ? ReasonCode::DATABASE_ATTACHMENT_IN_SYNC : ReasonCode::DATABASE_ATTACHMENT_DIFFERENCE,
        );
    }
}
