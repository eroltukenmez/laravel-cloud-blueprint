<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Observation;

use LaravelCloudBlueprint\Blueprint\ApplicationDefinition;
use LaravelCloudBlueprint\Cloud\DTO\CloudApplication;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\State\StateResource;

final readonly class ApplicationObservationFactory
{
    public function create(
        ApplicationDefinition $desired,
        ?StateResource $managed,
        ApplicationObservationEvidence $evidence,
    ): ResourceObservation {
        $address = new ResourceAddress(ResourceType::APPLICATION, $desired->name);
        if ($evidence->ownershipConflict || ($managed !== null && !$this->validState($managed, $address))) {
            return $this->observation(
                $address,
                ObservationKind::IDENTITY_CONFLICT,
                OwnershipStatus::CONFLICT,
                ReconciliationStatus::UNSUPPORTED,
                EvidenceStatus::COMPLETE,
            );
        }
        if ($evidence->completeness === EvidenceStatus::INCOMPLETE) {
            return $this->unknown($address, $managed === null ? OwnershipStatus::UNKNOWN : OwnershipStatus::MANAGED);
        }

        $applications = $evidence->applications();
        if ($managed === null) {
            $matches = array_values(array_filter(
                $applications,
                static fn (CloudApplication $application): bool => $application->name === $desired->name,
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
            $applications,
            static fn (CloudApplication $application): bool => $application->id === $managed->remoteId,
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
                $applications,
                static fn (CloudApplication $application): bool => $application->name === $desired->name,
            );

            return $this->observation(
                $address,
                $replacement === [] ? ObservationKind::IDENTITY_MISSING : ObservationKind::IDENTITY_REPLACEMENT,
                OwnershipStatus::MANAGED,
                ReconciliationStatus::UNSUPPORTED,
                EvidenceStatus::COMPLETE,
            );
        }
        if ($exact[0]->name !== $desired->name) {
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

    private function validState(StateResource $resource, ResourceAddress $address): bool
    {
        return $resource->type === ResourceType::APPLICATION
            && $resource->parent === null
            && (string) $resource->address === (string) $address;
    }

    private function compare(
        ResourceAddress $address,
        ApplicationDefinition $desired,
        CloudApplication $remote,
        OwnershipStatus $ownership,
    ): ResourceObservation {
        $fields = [];
        if ($remote->region !== $desired->region) {
            $fields[] = 'region';
        }
        if ($remote->repository === null) {
            if ($fields === []) {
                return $this->unknown($address, $ownership);
            }
        } elseif ($remote->repository !== $desired->source->repository) {
            $fields[] = 'repository';
        }
        if ($fields !== []) {
            return $this->observation(
                $address,
                ObservationKind::CONFIGURATION_DIFFERENCE,
                $ownership,
                $ownership === OwnershipStatus::MANAGED
                    ? ReconciliationStatus::UNSUPPORTED
                    : ReconciliationStatus::BLOCKED,
                EvidenceStatus::COMPLETE,
                new ChangedFields(...$fields),
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
        ?ChangedFields $fields = null,
    ): ResourceObservation {
        return new ResourceObservation($address, $kind, $ownership, $reconciliation, $evidence, $fields);
    }
}
