<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\Contract;

interface LaravelCloudLogicalDatabaseDeletionClient extends LaravelCloudDatabaseClient
{
    public function deleteDatabase(string $clusterId, string $databaseId): void;
}
