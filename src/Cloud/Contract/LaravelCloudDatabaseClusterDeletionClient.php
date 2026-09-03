<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\Contract;

interface LaravelCloudDatabaseClusterDeletionClient extends LaravelCloudDatabaseLifecycleClient, LaravelCloudLogicalDatabaseDeletionClient
{
    public function deleteDatabaseCluster(string $clusterId): void;
}
