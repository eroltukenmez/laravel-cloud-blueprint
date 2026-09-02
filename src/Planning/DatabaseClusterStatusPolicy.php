<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Planning;

use LaravelCloudBlueprint\Cloud\DTO\DatabaseClusterLifecycleReadiness;

final readonly class DatabaseClusterStatusPolicy
{
    public function destructiveReadiness(string $status): DatabaseClusterLifecycleReadiness
    {
        return match ($status) {
            'available' => DatabaseClusterLifecycleReadiness::ELIGIBLE,
            'creating', 'updating', 'restarting', 'upgrading', 'moving', 'restoring',
            'snapshotting_before_archiving', 'archiving', 'deleting',
            'stopped', 'restore_failed', 'disabled', 'archived', 'deleted' =>
                DatabaseClusterLifecycleReadiness::INELIGIBLE,
            default => DatabaseClusterLifecycleReadiness::UNKNOWN,
        };
    }

    public function unsupportedReason(string $status): ?string
    {
        return match ($status) {
            'available' => null,
            'creating', 'updating', 'restarting', 'upgrading', 'moving', 'restoring',
            'snapshotting_before_archiving', 'archiving', 'deleting' =>
                'Remote Database Cluster is in a transitional status; planning cannot compare it safely yet.',
            'stopped', 'restore_failed', 'disabled', 'archived', 'deleted', 'unknown' =>
                'Remote Database Cluster is not in a usable status and cannot be reconciled safely.',
            default => 'Remote Database Cluster status is unknown and cannot be reconciled safely.',
        };
    }
}
