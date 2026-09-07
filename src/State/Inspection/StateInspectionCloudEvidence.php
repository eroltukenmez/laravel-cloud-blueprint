<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\State\Inspection;

use LaravelCloudBlueprint\Cloud\DTO\CloudApplication;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabase;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabaseCluster;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironment;

final readonly class StateInspectionCloudEvidence
{
    /**
     * @param list<CloudApplication> $applications
     * @param list<CloudEnvironment> $environments
     * @param list<CloudDatabaseCluster> $databaseClusters
     * @param array<string, list<CloudDatabase>> $databasesByCluster
     */
    public function __construct(
        public bool $complete,
        public array $applications,
        public array $environments,
        public array $databaseClusters,
        public array $databasesByCluster,
    ) {
    }
}
