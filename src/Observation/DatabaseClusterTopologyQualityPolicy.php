<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Observation;

/** Evaluates the topology-evidence dimension only; it does not authorize deletion. */
final readonly class DatabaseClusterTopologyQualityPolicy
{
    public function isDestructiveQuality(DatabaseClusterTopologyEvidence $evidence): bool
    {
        return $evidence->synthesis === DatabaseClusterTopologySynthesis::CORROBORATED_COMPLETE;
    }
}
