<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\State\Inspection;

use LaravelCloudBlueprint\Cloud\DTO\CloudApplication;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabase;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabaseCluster;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironment;
use LaravelCloudBlueprint\Observation\DatabaseClusterScopedListEvidenceStatus;
use LaravelCloudBlueprint\Observation\DatabaseClusterTopologyEvidence;
use LaravelCloudBlueprint\Observation\DatabaseClusterTopologyEvidenceAssembler;

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
        /** @var array<string, list<CloudEnvironment>> */
        private array $environmentsByApplication = [],
        /** @var array<string, CloudDatabaseCluster|null> */
        private array $clustersById = [],
        /** @var array<string, array<string, CloudDatabase|null>> */
        private array $databasesById = [],
        /** @var array<string, true> */
        private array $failedEnvironmentReads = [],
        /** @var array<string, true> */
        private array $failedClusterReads = [],
        /** @var array<string, true> */
        private array $failedDatabaseListReads = [],
        /** @var array<string, true> */
        private array $failedDatabaseReads = [],
        private bool $applicationsReadFailed = false,
        private bool $databaseClustersReadFailed = false,
    ) {
    }

    public function applicationsReadFailed(): bool { return $this->applicationsReadFailed; }
    public function databaseClustersReadFailed(): bool { return $this->databaseClustersReadFailed; }
    /** @return list<CloudEnvironment>|null */
    public function environmentsFor(string $applicationId): ?array { return $this->environmentsByApplication[$applicationId] ?? null; }
    public function environmentReadFailed(string $applicationId): bool { return isset($this->failedEnvironmentReads[$applicationId]); }
    public function cluster(string $clusterId): ?CloudDatabaseCluster { return $this->clustersById[$clusterId] ?? null; }
    public function clusterReadFailed(string $clusterId): bool { return isset($this->failedClusterReads[$clusterId]); }
    public function databasesReadFailed(string $clusterId): bool { return isset($this->failedDatabaseListReads[$clusterId]); }
    public function database(string $clusterId, string $databaseId): ?CloudDatabase { return $this->databasesById[$clusterId][$databaseId] ?? null; }
    public function databaseReadFailed(string $clusterId, string $databaseId): bool { return isset($this->failedDatabaseReads[$clusterId . ':' . $databaseId]); }

    public function clusterTopology(string $clusterId): DatabaseClusterTopologyEvidence
    {
        return (new DatabaseClusterTopologyEvidenceAssembler())->assemble(
            $clusterId,
            $this->clustersById[$clusterId] ?? null,
            $this->databasesReadFailed($clusterId)
                ? DatabaseClusterScopedListEvidenceStatus::FAILED
                : (array_key_exists($clusterId, $this->databasesByCluster)
                    ? DatabaseClusterScopedListEvidenceStatus::COMPLETE
                    : DatabaseClusterScopedListEvidenceStatus::FAILED),
            $this->databasesByCluster[$clusterId] ?? [],
            $this->clusterReadFailed($clusterId),
        );
    }
}
