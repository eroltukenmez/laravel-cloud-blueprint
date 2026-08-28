<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Planning;

final readonly class DatabaseClusterStatusPolicy
{
    public function unsupportedReason(string $status): ?string
    {
        return match ($status) {
            'available' => null,
            'pending', 'creating', 'updating', 'provisioning' =>
                'Remote Database Cluster is in a transitional status; planning cannot compare it safely yet.',
            'failed', 'deleting', 'unavailable' =>
                'Remote Database Cluster is not in a usable status and cannot be reconciled safely.',
            default => 'Remote Database Cluster status is unknown and cannot be reconciled safely.',
        };
    }
}
