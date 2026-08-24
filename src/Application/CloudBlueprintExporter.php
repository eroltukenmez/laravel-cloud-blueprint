<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Application;

use LaravelCloudBlueprint\Blueprint\ApplicationDefinition;
use LaravelCloudBlueprint\Blueprint\Blueprint;
use LaravelCloudBlueprint\Blueprint\BlueprintSchemaVersion;
use LaravelCloudBlueprint\Blueprint\EnvironmentDefinition;
use LaravelCloudBlueprint\Blueprint\EnvironmentDefinitionCollection;
use LaravelCloudBlueprint\Blueprint\SourceDefinition;
use LaravelCloudBlueprint\Blueprint\SourceProvider;
use LaravelCloudBlueprint\Blueprint\VariableDefinitionCollection;
use LaravelCloudBlueprint\Cloud\DTO\CloudApplication;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironment;
use LaravelCloudBlueprint\Cloud\DTO\CloudOrganization;

final readonly class CloudBlueprintExporter
{
    /** @param list<CloudEnvironment> $environments */
    public function export(
        CloudOrganization $organization,
        CloudApplication $application,
        array $environments,
        ?SourceProvider $provider = null,
    ): Blueprint {
        $sourceProvider = $application->sourceProvider ?? $provider;
        if ($sourceProvider === null) {
            throw new CloudBlueprintExportException(
                'The source provider is unavailable from Laravel Cloud. Supply --provider.',
            );
        }

        if ($application->repository === null || !$this->isBlueprintRepository($application->repository)) {
            throw new CloudBlueprintExportException(
                'The application repository is unavailable or cannot be represented as owner/repository.',
            );
        }

        $definitions = [];
        foreach ($environments as $environment) {
            if ($environment->applicationId !== $application->id) {
                throw new CloudBlueprintExportException('Laravel Cloud returned an environment for another application.');
            }
            if ($environment->branch === null || trim($environment->branch) === '') {
                throw new CloudBlueprintExportException(sprintf(
                    'Branch information is unavailable for environment "%s".',
                    $environment->name,
                ));
            }
            $definitions[] = new EnvironmentDefinition(
                $environment->name,
                $environment->branch,
                new VariableDefinitionCollection(),
            );
        }

        return new Blueprint(
            BlueprintSchemaVersion::V1,
            $organization->slug,
            new ApplicationDefinition(
                $application->name,
                $application->region,
                new SourceDefinition($sourceProvider, $application->repository),
            ),
            new EnvironmentDefinitionCollection(...$definitions),
        );
    }

    private function isBlueprintRepository(string $repository): bool
    {
        return preg_match('/^[^\s\/]+\/[^\s\/]+$/D', $repository) === 1;
    }
}
