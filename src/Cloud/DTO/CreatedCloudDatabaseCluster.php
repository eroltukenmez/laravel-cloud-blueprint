<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\DTO;

use InvalidArgumentException;

final readonly class CreatedCloudDatabaseCluster
{
    public function __construct(
        public CloudDatabaseCluster $cluster,
        public string $defaultDatabaseId,
        public ?string $defaultDatabaseName = null,
    ) {
        if (trim($defaultDatabaseId) === '') {
            throw new InvalidArgumentException('Created default Database identity must not be empty.');
        }
        if (!$cluster->childDiscoveryComplete || $cluster->databaseIds !== [$defaultDatabaseId]) {
            throw new InvalidArgumentException(
                'Created Database Cluster must contain exactly its authoritative default Database identity.',
            );
        }
        if ($defaultDatabaseName !== null && trim($defaultDatabaseName) === '') {
            throw new InvalidArgumentException('Created default Database name must be non-empty when available.');
        }
    }
}
