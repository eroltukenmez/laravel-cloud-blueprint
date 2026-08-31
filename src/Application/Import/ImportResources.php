<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Application\Import;

use LaravelCloudBlueprint\Blueprint\Blueprint;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudClient;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudDatabaseClient;
use LaravelCloudBlueprint\Cloud\DTO\CloudApplication;
use LaravelCloudBlueprint\Cloud\Exception\CloudResponseException;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\Planning\Exception\OrganizationMismatchException;
use LaravelCloudBlueprint\State\Contract\StateStore;
use LaravelCloudBlueprint\State\StateDocument;
use LaravelCloudBlueprint\State\StateResource;

final readonly class ImportResources
{
    public function __construct(private CreateImportProposal $proposals)
    {
    }

    public function preview(
        Blueprint $blueprint,
        LaravelCloudClient $cloud,
        StateStore $states,
    ): ImportProposal {
        return $this->discover($blueprint, $states->load(), $cloud);
    }

    public function execute(
        Blueprint $blueprint,
        LaravelCloudClient $cloud,
        StateStore $states,
    ): ImportResult {
        $transaction = $states->begin();

        try {
            $state = $transaction->load();
            $proposal = $this->discover($blueprint, $state, $cloud);
            $this->assertAdoptable($proposal);

            $adopted = [];
            foreach ($proposal as $candidate) {
                if ($candidate->status !== ImportStatus::IMPORTABLE) {
                    continue;
                }
                if ($candidate->remoteId === null) {
                    throw new ImportRefusedException('An importable resource has no remote identity.', $proposal);
                }
                $state = $state->withResource(new StateResource(
                    $candidate->address,
                    $candidate->type,
                    $candidate->remoteId,
                    $candidate->parent,
                ));
                $adopted[] = $candidate;
            }

            if ($adopted === []) {
                return new ImportResult($proposal, $state);
            }

            if ($state->organization === null) {
                $state = $state->withOrganization($blueprint->organization);
            }

            return new ImportResult($proposal, $transaction->save($state), ...$adopted);
        } finally {
            $transaction->release();
        }
    }

    public function assertAdoptable(ImportProposal $proposal): void
    {
        if ($proposal->countByStatus(ImportStatus::CONFLICT) > 0
            || $proposal->countByStatus(ImportStatus::UNSUPPORTED) > 0) {
            throw new ImportRefusedException(
                'Import contains conflicted or unsupported resources. No state resources were changed.',
                $proposal,
            );
        }
    }

    private function discover(
        Blueprint $blueprint,
        StateDocument $state,
        LaravelCloudClient $cloud,
    ): ImportProposal {
        if ($state->organization !== null && $state->organization !== $blueprint->organization) {
            throw new ImportRefusedException(sprintf(
                'Local state belongs to organization "%s", not "%s".',
                $state->organization,
                $blueprint->organization,
            ));
        }

        $organization = $cloud->organization();
        if ($organization->slug !== $blueprint->organization) {
            throw new OrganizationMismatchException($blueprint->organization, $organization->slug);
        }

        $applications = $cloud->applications();
        $matchingApplications = array_values(array_filter(
            $applications,
            static fn (CloudApplication $application): bool => $application->name === $blueprint->application->name,
        ));
        $environments = [];
        foreach ($matchingApplications as $application) {
            foreach ($cloud->environments($application->id) as $environment) {
                $environments[] = $environment;
            }
        }

        $databaseClusters = [];
        $databasesByCluster = [];
        if (count($blueprint->databaseClusters) > 0) {
            if (!$cloud instanceof LaravelCloudDatabaseClient) {
                throw new CloudResponseException(
                    'The configured Laravel Cloud client does not support Database discovery.',
                    'GET',
                    '/databases/clusters',
                );
            }
            $databaseClusters = $cloud->databaseClusters();
            $requiredClusterIds = [];
            foreach ($blueprint->databaseClusters as $desiredCluster) {
                $address = new ResourceAddress(ResourceType::DATABASE_CLUSTER, $desiredCluster->name);
                $managed = $state->find($address);
                if ($managed !== null) {
                    $requiredClusterIds[$managed->remoteId] = true;
                    continue;
                }

                $matchingClusterIds = [];
                foreach ($databaseClusters as $remoteCluster) {
                    if ($remoteCluster->name === $desiredCluster->name) {
                        $matchingClusterIds[] = $remoteCluster->id;
                    }
                }
                if (count($matchingClusterIds) === 1) {
                    $requiredClusterIds[$matchingClusterIds[0]] = true;
                }
            }
            foreach (array_keys($requiredClusterIds) as $clusterId) {
                $databasesByCluster[$clusterId] = $cloud->databases($clusterId);
            }
        }

        return $this->proposals->create(
            $blueprint,
            $state,
            $applications,
            $environments,
            $databaseClusters,
            $databasesByCluster,
        );
    }
}
