<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Observation;

use InvalidArgumentException;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;

final readonly class ResourceObservation
{
    public ResourceType $resourceType;
    public ChangedFields $changedFields;

    public function __construct(
        public ResourceAddress $address,
        public ObservationKind $observation,
        public OwnershipStatus $ownership,
        public ReconciliationStatus $reconciliation,
        public EvidenceStatus $evidence,
        ?ChangedFields $changedFields = null,
        public ?ReasonCode $reasonCode = null,
    ) {
        $this->resourceType = $address->type;
        $this->changedFields = $changedFields ?? ChangedFields::none();

        $requiresCompleteEvidence = in_array($observation, [
            ObservationKind::IN_SYNC,
            ObservationKind::CONFIGURATION_DIFFERENCE,
            ObservationKind::IDENTITY_MISSING,
            ObservationKind::IDENTITY_REPLACEMENT,
        ], true);
        if ($requiresCompleteEvidence && $evidence !== EvidenceStatus::COMPLETE) {
            throw new InvalidArgumentException('This observation kind requires complete evidence.');
        }
        if ($observation === ObservationKind::UNKNOWN && $evidence !== EvidenceStatus::INCOMPLETE) {
            throw new InvalidArgumentException('An unknown observation requires incomplete evidence.');
        }
        if ($this->changedFields->count() > 0 && $observation !== ObservationKind::CONFIGURATION_DIFFERENCE) {
            throw new InvalidArgumentException('Changed fields are valid only for a configuration difference.');
        }
        if ($observation === ObservationKind::CONFIGURATION_DIFFERENCE && $this->changedFields->count() === 0) {
            throw new InvalidArgumentException('A configuration difference requires at least one changed field.');
        }
    }
}
