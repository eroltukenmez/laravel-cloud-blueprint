<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Blueprint;

final readonly class Blueprint
{
    public function __construct(
        public BlueprintSchemaVersion $schemaVersion,
        public string $organization,
        public ApplicationDefinition $application,
        public EnvironmentDefinitionCollection $environments,
        public DatabaseClusterDefinitionCollection $databaseClusters = new DatabaseClusterDefinitionCollection(),
    ) {
    }
}
