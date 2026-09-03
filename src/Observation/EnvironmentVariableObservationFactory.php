<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Observation;

use InvalidArgumentException;
use LaravelCloudBlueprint\Blueprint\VariableDefinition;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;

final readonly class EnvironmentVariableObservationFactory
{
    public function create(
        ResourceAddress $address,
        VariableDefinition $desired,
        string $desiredValue,
        EnvironmentVariableObservationEvidence $evidence,
    ): ResourceObservation {
        if ($address->type !== ResourceType::VARIABLE) {
            throw new InvalidArgumentException('Environment Variable observations require a variable address.');
        }
        if ($evidence->status !== EnvironmentVariableEvidenceStatus::AVAILABLE) {
            return new ResourceObservation(
                $address,
                ObservationKind::UNKNOWN,
                OwnershipStatus::NONE,
                ReconciliationStatus::BLOCKED,
                EvidenceStatus::INCOMPLETE,
            );
        }

        $variables = $evidence->variables();
        if ($variables === null) {
            return new ResourceObservation(
                $address,
                ObservationKind::UNKNOWN,
                OwnershipStatus::NONE,
                ReconciliationStatus::BLOCKED,
                EvidenceStatus::INCOMPLETE,
            );
        }
        $remote = $variables->find($desired->name);
        if ($remote === null) {
            return new ResourceObservation(
                $address,
                ObservationKind::DESIRED_RESOURCE_MISSING,
                OwnershipStatus::NONE,
                ReconciliationStatus::SUPPORTED,
                EvidenceStatus::COMPLETE,
            );
        }
        if ($remote->value === $desiredValue) {
            return new ResourceObservation(
                $address,
                ObservationKind::IN_SYNC,
                OwnershipStatus::NONE,
                ReconciliationStatus::NOT_APPLICABLE,
                EvidenceStatus::COMPLETE,
            );
        }

        return new ResourceObservation(
            $address,
            ObservationKind::CONFIGURATION_DIFFERENCE,
            OwnershipStatus::NONE,
            ReconciliationStatus::SUPPORTED,
            EvidenceStatus::COMPLETE,
            new ChangedFields('value'),
            ReasonCode::ENVIRONMENT_VARIABLE_VALUE_DIFFERENCE,
        );
    }
}
