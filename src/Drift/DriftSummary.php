<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Drift;

use LaravelCloudBlueprint\Observation\ObservationKind;
use LaravelCloudBlueprint\Observation\ResourceObservationCollection;

final readonly class DriftSummary
{
    public int $inSync;
    public int $desiredResourceMissing;
    public int $desiredResourceAbsent;
    public int $configurationDifference;
    public int $identityMissing;
    public int $identityReplacement;
    public int $identityConflict;
    public int $lifecycleCondition;
    public int $unknown;

    public function __construct(ResourceObservationCollection $observations)
    {
        $counts = array_fill_keys(array_column(ObservationKind::cases(), 'value'), 0);
        foreach ($observations as $observation) {
            ++$counts[$observation->observation->value];
        }
        $this->inSync = $counts[ObservationKind::IN_SYNC->value];
        $this->desiredResourceMissing = $counts[ObservationKind::DESIRED_RESOURCE_MISSING->value];
        $this->desiredResourceAbsent = $counts[ObservationKind::DESIRED_RESOURCE_ABSENT->value];
        $this->configurationDifference = $counts[ObservationKind::CONFIGURATION_DIFFERENCE->value];
        $this->identityMissing = $counts[ObservationKind::IDENTITY_MISSING->value];
        $this->identityReplacement = $counts[ObservationKind::IDENTITY_REPLACEMENT->value];
        $this->identityConflict = $counts[ObservationKind::IDENTITY_CONFLICT->value];
        $this->lifecycleCondition = $counts[ObservationKind::LIFECYCLE_CONDITION->value];
        $this->unknown = $counts[ObservationKind::UNKNOWN->value];
    }

    public function total(): int
    {
        return $this->inSync
            + $this->desiredResourceMissing
            + $this->desiredResourceAbsent
            + $this->configurationDifference
            + $this->identityMissing
            + $this->identityReplacement
            + $this->identityConflict
            + $this->lifecycleCondition
            + $this->unknown;
    }

    public function count(ObservationKind $kind): int
    {
        return match ($kind) {
            ObservationKind::IN_SYNC => $this->inSync,
            ObservationKind::DESIRED_RESOURCE_MISSING => $this->desiredResourceMissing,
            ObservationKind::DESIRED_RESOURCE_ABSENT => $this->desiredResourceAbsent,
            ObservationKind::CONFIGURATION_DIFFERENCE => $this->configurationDifference,
            ObservationKind::IDENTITY_MISSING => $this->identityMissing,
            ObservationKind::IDENTITY_REPLACEMENT => $this->identityReplacement,
            ObservationKind::IDENTITY_CONFLICT => $this->identityConflict,
            ObservationKind::LIFECYCLE_CONDITION => $this->lifecycleCondition,
            ObservationKind::UNKNOWN => $this->unknown,
        };
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        $counts = ['total' => $this->total()];
        foreach (ObservationKind::cases() as $kind) {
            $counts[$kind->value] = $this->count($kind);
        }

        return $counts;
    }
}
