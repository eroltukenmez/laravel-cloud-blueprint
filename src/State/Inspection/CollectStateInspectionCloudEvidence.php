<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\State\Inspection;

use LaravelCloudBlueprint\Cloud\Contract\StateInspectionCloudReader;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudScopedDatabaseListReader;
use LaravelCloudBlueprint\Cloud\DTO\CloudApplication;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabase;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabaseCluster;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabaseScopedList;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironment;
use LaravelCloudBlueprint\Cloud\Exception\CloudException;
use LaravelCloudBlueprint\Cloud\Exception\CloudResourceNotFoundException;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\State\StateDocument;
use LaravelCloudBlueprint\Observation\DatabaseClusterTopologySynthesis;

/** Collects the single authoritative, read-only Cloud evidence pass for state:inspect. */
final readonly class CollectStateInspectionCloudEvidence
{
    public function collect(StateDocument $state, StateInspectionCloudReader $cloud): StateInspectionCloudEvidence
    {
        $complete = true;
        /** @var list<CloudApplication> $applications */
        $applications = [];
        $applicationsReadFailed = false;
        try {
            $applications = $this->applications($cloud->applications());
        } catch (CloudException|\UnexpectedValueException) {
            $applications = [];
            $complete = false;
            $applicationsReadFailed = true;
        }

        /** @var list<CloudEnvironment> $environments */
        $environments = [];
        /** @var array<string, list<CloudEnvironment>> $environmentsByApplication */
        $environmentsByApplication = [];
        /** @var array<string, true> $failedEnvironmentReads */
        $failedEnvironmentReads = [];
        foreach ($this->environmentParentIds($state) as $applicationId) {
            try {
                $listed = $this->environments($cloud->environments($applicationId));
                $environmentsByApplication[$applicationId] = $listed;
                array_push($environments, ...$listed);
            } catch (CloudException|\UnexpectedValueException) {
                $complete = false;
                $failedEnvironmentReads[$applicationId] = true;
            }
        }

        /** @var list<CloudDatabaseCluster> $clusters */
        $clusters = [];
        $databaseClustersReadFailed = false;
        try {
            $clusters = $this->clusters($cloud->databaseClusters());
        } catch (CloudException|\UnexpectedValueException) {
            $clusters = [];
            $complete = false;
            $databaseClustersReadFailed = true;
        }

        /** @var array<string, list<CloudDatabase>> $databasesByCluster */
        $databasesByCluster = [];
        /** @var array<string, CloudDatabaseScopedList> $scopedDatabaseListsByCluster */
        $scopedDatabaseListsByCluster = [];
        /** @var array<string, true> $failedDatabaseListReads */
        $failedDatabaseListReads = [];
        /** @var array<string, CloudDatabaseCluster|null> $clustersById */
        $clustersById = [];
        /** @var array<string, true> $failedClusterReads */
        $failedClusterReads = [];
        /** @var array<string, array<string, CloudDatabase|null>> $databasesById */
        $databasesById = [];
        /** @var array<string, true> $failedDatabaseReads */
        $failedDatabaseReads = [];
        foreach ($state->resources() as $resource) {
            if ($resource->type === ResourceType::DATABASE_CLUSTER) {
                try {
                    $exact = $cloud->databaseCluster($resource->remoteId);
                    $clustersById[$resource->remoteId] = $exact;
                } catch (CloudResourceNotFoundException) {
                    $clustersById[$resource->remoteId] = null;
                } catch (CloudException) {
                    $complete = false;
                    $failedClusterReads[$resource->remoteId] = true;
                }
                try {
                    if ($cloud instanceof LaravelCloudScopedDatabaseListReader) {
                        $scoped = $cloud->scopedDatabases($resource->remoteId);
                        $databasesByCluster[$resource->remoteId] = $this->databases($scoped->databases);
                        $scopedDatabaseListsByCluster[$resource->remoteId] = new CloudDatabaseScopedList(
                            $databasesByCluster[$resource->remoteId],
                            $scoped->status,
                            $scoped->paginationStatus,
                        );
                    } else {
                        $databasesByCluster[$resource->remoteId] = $this->databases($cloud->databases($resource->remoteId));
                    }
                } catch (CloudException|\UnexpectedValueException) {
                    $complete = false;
                    $failedDatabaseListReads[$resource->remoteId] = true;
                }
            }
            if ($resource->type === ResourceType::DATABASE && $resource->parent !== null) {
                $parent = $state->find($resource->parent);
                if ($parent === null) {
                    $complete = false;
                    continue;
                }
                try {
                    $exact = $cloud->database($parent->remoteId, $resource->remoteId);
                    if ($exact->id !== $resource->remoteId) {
                        $complete = false;
                    }
                    $databasesById[$parent->remoteId][$resource->remoteId] = $exact;
                } catch (CloudResourceNotFoundException) {
                    $databasesById[$parent->remoteId][$resource->remoteId] = null;
                } catch (CloudException) {
                    $complete = false;
                    $failedDatabaseReads[$parent->remoteId . ':' . $resource->remoteId] = true;
                }
            }
        }

        if (!$this->uniqueIds($applications) || !$this->uniqueIds($environments) || !$this->uniqueIds($clusters)) {
            $complete = false;
        }
        foreach ($databasesByCluster as $databases) {
            if (!$this->uniqueIds($databases)) {
                $complete = false;
            }
        }

        $evidence = new StateInspectionCloudEvidence($complete, $applications, $environments, $clusters, $databasesByCluster,
            $environmentsByApplication, $clustersById, $databasesById, $failedEnvironmentReads, $failedClusterReads,
            $failedDatabaseListReads, $failedDatabaseReads, $applicationsReadFailed, $databaseClustersReadFailed,
            $scopedDatabaseListsByCluster);

        foreach ($state->resources() as $resource) {
            if ($resource->type !== ResourceType::DATABASE_CLUSTER || $evidence->cluster($resource->remoteId) === null) {
                continue;
            }
            if (!in_array($evidence->clusterTopology($resource->remoteId)->synthesis, [
                DatabaseClusterTopologySynthesis::CORROBORATED_COMPLETE,
                DatabaseClusterTopologySynthesis::SCOPED_COMPLETE,
            ], true)) {
                $complete = false;
            }
        }

        return new StateInspectionCloudEvidence($complete, $applications, $environments, $clusters, $databasesByCluster,
            $environmentsByApplication, $clustersById, $databasesById, $failedEnvironmentReads, $failedClusterReads,
            $failedDatabaseListReads, $failedDatabaseReads, $applicationsReadFailed, $databaseClustersReadFailed);
    }

    /** @return list<string> */
    private function environmentParentIds(StateDocument $state): array
    {
        $ids = [];
        foreach ($state->resources() as $resource) {
            if ($resource->type !== ResourceType::ENVIRONMENT || $resource->parent === null) {
                continue;
            }
            $parent = $state->find($resource->parent);
            if ($parent !== null) {
                $ids[$parent->remoteId] = true;
            }
        }
        return array_keys($ids);
    }

    /**
     * @param array<mixed> $value
     * @return list<CloudApplication>
     */
    private function applications(array $value): array { return $this->listOf($value, CloudApplication::class); }
    /**
     * @param array<mixed> $value
     * @return list<CloudEnvironment>
     */
    private function environments(array $value): array { return $this->listOf($value, CloudEnvironment::class); }
    /**
     * @param array<mixed> $value
     * @return list<CloudDatabaseCluster>
     */
    private function clusters(array $value): array { return $this->listOf($value, CloudDatabaseCluster::class); }
    /**
     * @param array<mixed> $value
     * @return list<CloudDatabase>
     */
    private function databases(array $value): array { return $this->listOf($value, CloudDatabase::class); }

    /**
     * @template T of object
     * @param array<mixed> $value
     * @param class-string<T> $class
     * @return list<T>
     */
    private function listOf(array $value, string $class): array
    {
        if (!array_is_list($value)) {
            throw new \UnexpectedValueException('Cloud list response is malformed.');
        }
        $listed = [];
        foreach ($value as $item) {
            if (!$item instanceof $class) {
                throw new \UnexpectedValueException('Cloud list response contains an unexpected resource.');
            }
            $listed[] = $item;
        }
        return $listed;
    }

    /** @param list<CloudApplication|CloudEnvironment|CloudDatabaseCluster|CloudDatabase> $resources */
    private function uniqueIds(array $resources): bool
    {
        $ids = [];
        foreach ($resources as $resource) {
            /** @var object{id: string} $resource */
            if (isset($ids[$resource->id])) {
                return false;
            }
            $ids[$resource->id] = true;
        }
        return true;
    }
}
