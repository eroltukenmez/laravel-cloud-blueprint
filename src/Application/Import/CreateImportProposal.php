<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Application\Import;

use LaravelCloudBlueprint\Blueprint\Blueprint;
use LaravelCloudBlueprint\Cloud\DTO\CloudApplication;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabase;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabaseCluster;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironment;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\State\StateDocument;
use LaravelCloudBlueprint\State\StateResource;

final readonly class CreateImportProposal
{
    /**
     * @param list<CloudApplication> $applications
     * @param list<CloudEnvironment> $environments
     * @param list<CloudDatabaseCluster> $databaseClusters
     * @param array<string, list<CloudDatabase>> $databasesByCluster
     */
    public function create(
        Blueprint $blueprint,
        StateDocument $state,
        array $applications,
        array $environments,
        array $databaseClusters = [],
        array $databasesByCluster = [],
    ): ImportProposal {
        $applicationAddress = new ResourceAddress(ResourceType::APPLICATION, $blueprint->application->name);
        $applicationMatches = array_values(array_filter(
            $applications,
            static fn (CloudApplication $application): bool => $application->name === $blueprint->application->name,
        ));
        $application = count($applicationMatches) === 1 ? $applicationMatches[0] : null;
        $applicationCandidate = $this->applicationCandidate(
            $applicationAddress,
            $blueprint->application->name,
            $applicationMatches,
            $state,
        );

        $candidates = [$applicationCandidate];
        $desiredEnvironments = iterator_to_array($blueprint->environments, false);
        usort(
            $desiredEnvironments,
            static fn ($left, $right): int => strcmp($left->name, $right->name),
        );

        foreach ($desiredEnvironments as $desired) {
            $address = new ResourceAddress(ResourceType::ENVIRONMENT, $desired->name);
            if ($applicationCandidate->status === ImportStatus::CONFLICT) {
                $candidates[] = new ImportCandidate(
                    $address,
                    ResourceType::ENVIRONMENT,
                    null,
                    $desired->name,
                    $applicationAddress,
                    ImportStatus::CONFLICT,
                    'Parent application identity is conflicted.',
                );
                continue;
            }
            if ($application === null) {
                $candidates[] = new ImportCandidate(
                    $address,
                    ResourceType::ENVIRONMENT,
                    null,
                    $desired->name,
                    $applicationAddress,
                    ImportStatus::UNSUPPORTED,
                    'Parent application identity cannot be resolved safely.',
                );
                continue;
            }

            $named = array_values(array_filter(
                $environments,
                static fn (CloudEnvironment $environment): bool => $environment->name === $desired->name,
            ));
            $scoped = array_values(array_filter(
                $named,
                static fn (CloudEnvironment $environment): bool => $environment->applicationId === $application->id,
            ));
            $candidates[] = $this->environmentCandidate(
                $address,
                $applicationAddress,
                $desired->name,
                $named,
                $scoped,
                $state,
            );
        }

        $desiredClusters = iterator_to_array($blueprint->databaseClusters, false);
        usort($desiredClusters, static fn ($left, $right): int => strcmp($left->name, $right->name));
        foreach ($desiredClusters as $desiredCluster) {
            $clusterAddress = new ResourceAddress(ResourceType::DATABASE_CLUSTER, $desiredCluster->name);
            $managedCluster = $state->find($clusterAddress);
            $remoteCluster = null;

            if ($managedCluster !== null) {
                $remoteCluster = $this->databaseClusterById($databaseClusters, $managedCluster->remoteId);
                if ($managedCluster->type !== ResourceType::DATABASE_CLUSTER || $managedCluster->parent !== null) {
                    $clusterCandidate = $this->candidate($clusterAddress, ResourceType::DATABASE_CLUSTER, null,
                        $desiredCluster->name, null, ImportStatus::CONFLICT,
                        'The logical address has incompatible Database Cluster ownership.');
                } elseif ($remoteCluster === null) {
                    $clusterCandidate = $this->candidate($clusterAddress, ResourceType::DATABASE_CLUSTER, null,
                        $desiredCluster->name, null, ImportStatus::UNSUPPORTED,
                        'The owned Database Cluster remote identity is missing.');
                } else {
                    $clusterCandidate = $this->compatibleClusterCandidate(
                        $clusterAddress, $desiredCluster->name, $desiredCluster->type->value,
                        $desiredCluster->region, $remoteCluster, $state,
                    );
                }
            } else {
                $matches = array_values(array_filter(
                    $databaseClusters,
                    static fn (CloudDatabaseCluster $cluster): bool => $cluster->name === $desiredCluster->name,
                ));
                if (count($matches) > 1) {
                    $clusterCandidate = $this->candidate($clusterAddress, ResourceType::DATABASE_CLUSTER, null,
                        $desiredCluster->name, null, ImportStatus::CONFLICT,
                        'Multiple remote Database Clusters match this address.');
                } elseif ($matches === []) {
                    $clusterCandidate = $this->candidate($clusterAddress, ResourceType::DATABASE_CLUSTER, null,
                        $desiredCluster->name, null, ImportStatus::UNSUPPORTED,
                        'No remote Database Cluster matches this address.');
                } else {
                    $remoteCluster = $matches[0];
                    $clusterCandidate = $this->compatibleClusterCandidate(
                        $clusterAddress, $desiredCluster->name, $desiredCluster->type->value,
                        $desiredCluster->region, $remoteCluster, $state,
                    );
                }
            }
            $candidates[] = $clusterCandidate;

            foreach ($desiredCluster->databases as $desiredDatabase) {
                $databaseAddress = new ResourceAddress(
                    ResourceType::DATABASE,
                    $desiredCluster->name . '.' . $desiredDatabase->name,
                );
                if ($remoteCluster === null
                    || ($clusterCandidate->status !== ImportStatus::IMPORTABLE
                        && $clusterCandidate->status !== ImportStatus::ALREADY_MANAGED)) {
                    $candidates[] = $this->candidate(
                        $databaseAddress,
                        ResourceType::DATABASE,
                        null,
                        $desiredDatabase->name,
                        $clusterAddress,
                        $clusterCandidate->status === ImportStatus::CONFLICT ? ImportStatus::CONFLICT : ImportStatus::UNSUPPORTED,
                        'Parent Database Cluster identity cannot be resolved safely.',
                    );
                    continue;
                }

                $remoteDatabases = $databasesByCluster[$remoteCluster->id] ?? [];
                $managedDatabase = $state->find($databaseAddress);
                if ($managedDatabase !== null) {
                    $remoteDatabase = $this->databaseById($remoteDatabases, $managedDatabase->remoteId);
                    if ($managedDatabase->type !== ResourceType::DATABASE
                        || $managedDatabase->parent === null
                        || (string) $managedDatabase->parent !== (string) $clusterAddress) {
                        $candidates[] = $this->candidate($databaseAddress, ResourceType::DATABASE, null,
                            $desiredDatabase->name, $clusterAddress, ImportStatus::CONFLICT,
                            'The logical address has incompatible logical Database ownership.');
                    } elseif ($remoteDatabase === null) {
                        $candidates[] = $this->candidate($databaseAddress, ResourceType::DATABASE, null,
                            $desiredDatabase->name, $clusterAddress, ImportStatus::UNSUPPORTED,
                            'The owned logical Database remote identity is missing from its expected Cluster.');
                    } elseif ($remoteDatabase->name !== $desiredDatabase->name) {
                        $candidates[] = $this->candidate($databaseAddress, ResourceType::DATABASE, $remoteDatabase->id,
                            $remoteDatabase->name, $clusterAddress, ImportStatus::CONFLICT,
                            'The owned logical Database name does not match its blueprint address.');
                    } else {
                        $candidates[] = $this->candidateForIdentity(
                            $databaseAddress, ResourceType::DATABASE, $remoteDatabase->id,
                            $remoteDatabase->name, $clusterAddress, $state,
                        );
                    }
                    continue;
                }

                $matches = array_values(array_filter(
                    $remoteDatabases,
                    static fn (CloudDatabase $database): bool => $database->name === $desiredDatabase->name,
                ));
                if (count($matches) > 1) {
                    $candidates[] = $this->candidate($databaseAddress, ResourceType::DATABASE, null,
                        $desiredDatabase->name, $clusterAddress, ImportStatus::CONFLICT,
                        'Multiple logical Databases match this address under the parent Cluster.');
                } elseif ($matches === []) {
                    $candidates[] = $this->candidate($databaseAddress, ResourceType::DATABASE, null,
                        $desiredDatabase->name, $clusterAddress, ImportStatus::UNSUPPORTED,
                        'No logical Database matches this address under the parent Cluster.');
                } else {
                    $candidates[] = $this->candidateForIdentity(
                        $databaseAddress, ResourceType::DATABASE, $matches[0]->id,
                        $matches[0]->name, $clusterAddress, $state,
                    );
                }
            }
        }

        return new ImportProposal(...$candidates);
    }

    private function compatibleClusterCandidate(
        ResourceAddress $address,
        string $desiredName,
        string $desiredType,
        string $desiredRegion,
        CloudDatabaseCluster $remote,
        StateDocument $state,
    ): ImportCandidate {
        if ($remote->name !== $desiredName) {
            return $this->candidate($address, ResourceType::DATABASE_CLUSTER, $remote->id, $remote->name, null,
                ImportStatus::CONFLICT, 'The owned Database Cluster name does not match its blueprint address.');
        }
        if ($remote->type !== $desiredType) {
            return $this->candidate($address, ResourceType::DATABASE_CLUSTER, $remote->id, $remote->name, null,
                ImportStatus::UNSUPPORTED, 'The remote Database Cluster type is incompatible with the blueprint.');
        }
        if ($remote->region !== $desiredRegion) {
            return $this->candidate($address, ResourceType::DATABASE_CLUSTER, $remote->id, $remote->name, null,
                ImportStatus::UNSUPPORTED, 'The remote Database Cluster region is incompatible with the blueprint.');
        }

        return $this->candidateForIdentity(
            $address, ResourceType::DATABASE_CLUSTER, $remote->id, $remote->name, null, $state,
        );
    }

    /** @param list<CloudDatabaseCluster> $clusters */
    private function databaseClusterById(array $clusters, string $id): ?CloudDatabaseCluster
    {
        foreach ($clusters as $cluster) {
            if ($cluster->id === $id) {
                return $cluster;
            }
        }
        return null;
    }

    /** @param list<CloudDatabase> $databases */
    private function databaseById(array $databases, string $id): ?CloudDatabase
    {
        foreach ($databases as $database) {
            if ($database->id === $id) {
                return $database;
            }
        }
        return null;
    }

    /** @param list<CloudApplication> $matches */
    private function applicationCandidate(
        ResourceAddress $address,
        string $name,
        array $matches,
        StateDocument $state,
    ): ImportCandidate {
        if (count($matches) > 1) {
            return $this->candidate($address, ResourceType::APPLICATION, null, $name, null, ImportStatus::CONFLICT,
                'Multiple remote applications match this address.');
        }
        if ($matches === []) {
            return $this->candidate($address, ResourceType::APPLICATION, null, $name, null, ImportStatus::UNSUPPORTED,
                'No remote application matches this address.');
        }

        return $this->candidateForIdentity($address, ResourceType::APPLICATION, $matches[0]->id, $matches[0]->name, null, $state);
    }

    /**
     * @param list<CloudEnvironment> $named
     * @param list<CloudEnvironment> $scoped
     */
    private function environmentCandidate(
        ResourceAddress $address,
        ResourceAddress $parent,
        string $name,
        array $named,
        array $scoped,
        StateDocument $state,
    ): ImportCandidate {
        if (count($scoped) > 1) {
            return $this->candidate($address, ResourceType::ENVIRONMENT, null, $name, $parent, ImportStatus::CONFLICT,
                'Multiple remote environments match this address under the parent application.');
        }
        if ($scoped === [] && $named !== []) {
            return $this->candidate($address, ResourceType::ENVIRONMENT, null, $name, $parent, ImportStatus::CONFLICT,
                'A matching remote environment belongs to a different application.');
        }
        if ($scoped === []) {
            return $this->candidate($address, ResourceType::ENVIRONMENT, null, $name, $parent, ImportStatus::UNSUPPORTED,
                'No remote environment matches this address under the parent application.');
        }

        return $this->candidateForIdentity(
            $address,
            ResourceType::ENVIRONMENT,
            $scoped[0]->id,
            $scoped[0]->name,
            $parent,
            $state,
        );
    }

    private function candidateForIdentity(
        ResourceAddress $address,
        ResourceType $type,
        string $remoteId,
        string $remoteName,
        ?ResourceAddress $parent,
        StateDocument $state,
    ): ImportCandidate {
        $managed = $state->find($address);
        if ($managed !== null) {
            if ($this->sameIdentity($managed, $type, $remoteId, $parent)) {
                return $this->candidate($address, $type, $remoteId, $remoteName, $parent, ImportStatus::ALREADY_MANAGED,
                    'The same remote identity is already managed at this address.');
            }

            return $this->candidate($address, $type, $remoteId, $remoteName, $parent, ImportStatus::CONFLICT,
                'The logical address is already managed with a different or incompatible identity.');
        }

        foreach ($state->resources() as $resource) {
            if ($resource->remoteId === $remoteId) {
                return $this->candidate($address, $type, $remoteId, $remoteName, $parent, ImportStatus::CONFLICT,
                    sprintf('The remote identity is already managed by "%s".', (string) $resource->address));
            }
        }

        return $this->candidate($address, $type, $remoteId, $remoteName, $parent, ImportStatus::IMPORTABLE,
            'The remote identity can be adopted into local state.');
    }

    private function sameIdentity(
        StateResource $managed,
        ResourceType $type,
        string $remoteId,
        ?ResourceAddress $parent,
    ): bool {
        return $managed->type === $type
            && $managed->remoteId === $remoteId
            && !$managed->isDerived()
            && ($managed->parent === null ? null : (string) $managed->parent)
                === ($parent === null ? null : (string) $parent);
    }

    private function candidate(
        ResourceAddress $address,
        ResourceType $type,
        ?string $remoteId,
        string $remoteName,
        ?ResourceAddress $parent,
        ImportStatus $status,
        string $reason,
    ): ImportCandidate {
        return new ImportCandidate($address, $type, $remoteId, $remoteName, $parent, $status, $reason);
    }
}
