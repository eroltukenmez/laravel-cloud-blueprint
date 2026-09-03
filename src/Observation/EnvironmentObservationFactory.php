<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Observation;

use LaravelCloudBlueprint\Blueprint\EnvironmentDefinition;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironment;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\State\StateResource;

final readonly class EnvironmentObservationFactory
{
    public function create(
        EnvironmentDefinition $desired,
        ?StateResource $managed,
        EnvironmentObservationEvidence $evidence,
    ): ResourceObservation {
        $address = new ResourceAddress(ResourceType::ENVIRONMENT, $desired->name);
        if ($evidence->ownershipConflict
            || ($managed !== null && !$this->validState($managed, $address, $evidence->parent->address))) {
            return $this->observation(
                $address,
                ObservationKind::IDENTITY_CONFLICT,
                OwnershipStatus::CONFLICT,
                ReconciliationStatus::UNSUPPORTED,
                EvidenceStatus::COMPLETE,
            );
        }
        if ($evidence->parent->completeness === EvidenceStatus::INCOMPLETE
            || $evidence->completeness === EvidenceStatus::INCOMPLETE) {
            return $this->unknown($address, $managed === null ? OwnershipStatus::UNKNOWN : OwnershipStatus::MANAGED);
        }
        if (!$evidence->parent->authorizesChildren() && $managed === null) {
            return $this->unknown($address, OwnershipStatus::NONE);
        }

        $parentId = $evidence->parent->remoteId;
        if ($parentId === null) {
            return $this->unknown($address, $managed === null ? OwnershipStatus::UNKNOWN : OwnershipStatus::MANAGED);
        }
        $environments = $evidence->environments();
        if ($managed === null) {
            $matches = array_values(array_filter(
                $environments,
                static fn (CloudEnvironment $environment): bool => $environment->applicationId === $parentId
                    && $environment->name === $desired->name,
            ));
            if ($matches === []) {
                return $this->observation(
                    $address,
                    ObservationKind::DESIRED_RESOURCE_MISSING,
                    OwnershipStatus::NONE,
                    ReconciliationStatus::SUPPORTED,
                    EvidenceStatus::COMPLETE,
                );
            }
            if (count($matches) !== 1) {
                return $this->observation(
                    $address,
                    ObservationKind::IDENTITY_CONFLICT,
                    OwnershipStatus::UNMANAGED,
                    ReconciliationStatus::BLOCKED,
                    EvidenceStatus::COMPLETE,
                );
            }

            return $this->compare($address, $desired, $matches[0], OwnershipStatus::UNMANAGED);
        }

        $exact = array_values(array_filter(
            $environments,
            static fn (CloudEnvironment $environment): bool => $environment->id === $managed->remoteId,
        ));
        if (count($exact) > 1) {
            return $this->observation(
                $address,
                ObservationKind::IDENTITY_CONFLICT,
                OwnershipStatus::CONFLICT,
                ReconciliationStatus::UNSUPPORTED,
                EvidenceStatus::COMPLETE,
            );
        }
        if ($exact === []) {
            $replacement = array_filter(
                $environments,
                static fn (CloudEnvironment $environment): bool => $environment->applicationId === $parentId
                    && $environment->name === $desired->name,
            );

            return $this->observation(
                $address,
                $replacement === [] ? ObservationKind::IDENTITY_MISSING : ObservationKind::IDENTITY_REPLACEMENT,
                OwnershipStatus::MANAGED,
                ReconciliationStatus::UNSUPPORTED,
                EvidenceStatus::COMPLETE,
            );
        }
        if ($exact[0]->applicationId !== $parentId || $exact[0]->name !== $desired->name) {
            return $this->observation(
                $address,
                ObservationKind::IDENTITY_CONFLICT,
                OwnershipStatus::MANAGED,
                ReconciliationStatus::UNSUPPORTED,
                EvidenceStatus::COMPLETE,
            );
        }

        return $this->compare($address, $desired, $exact[0], OwnershipStatus::MANAGED);
    }

    private function validState(
        StateResource $resource,
        ResourceAddress $address,
        ?ResourceAddress $parentAddress,
    ): bool
    {
        return $resource->type === ResourceType::ENVIRONMENT
            && $resource->parent?->type === ResourceType::APPLICATION
            && $parentAddress !== null
            && (string) $resource->parent === (string) $parentAddress
            && (string) $resource->address === (string) $address;
    }

    private function compare(
        ResourceAddress $address,
        EnvironmentDefinition $desired,
        CloudEnvironment $remote,
        OwnershipStatus $ownership,
    ): ResourceObservation {
        if ($remote->branch === null) {
            return $this->unknown($address, $ownership);
        }
        if ($remote->branch !== $desired->branch) {
            return new ResourceObservation(
                $address,
                ObservationKind::CONFIGURATION_DIFFERENCE,
                $ownership,
                $ownership === OwnershipStatus::MANAGED
                    ? ReconciliationStatus::SUPPORTED
                    : ReconciliationStatus::BLOCKED,
                EvidenceStatus::COMPLETE,
                new ChangedFields('branch'),
                ReasonCode::ENVIRONMENT_BRANCH_DIFFERENCE,
            );
        }

        return $this->observation(
            $address,
            ObservationKind::IN_SYNC,
            $ownership,
            ReconciliationStatus::NOT_APPLICABLE,
            EvidenceStatus::COMPLETE,
        );
    }

    private function unknown(ResourceAddress $address, OwnershipStatus $ownership): ResourceObservation
    {
        return $this->observation(
            $address,
            ObservationKind::UNKNOWN,
            $ownership,
            ReconciliationStatus::BLOCKED,
            EvidenceStatus::INCOMPLETE,
        );
    }

    private function observation(
        ResourceAddress $address,
        ObservationKind $kind,
        OwnershipStatus $ownership,
        ReconciliationStatus $reconciliation,
        EvidenceStatus $evidence,
    ): ResourceObservation {
        return new ResourceObservation($address, $kind, $ownership, $reconciliation, $evidence);
    }
}
