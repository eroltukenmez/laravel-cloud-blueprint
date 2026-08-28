<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Blueprint;

final readonly class DatabaseClusterDefinition
{
    public function __construct(
        public string $name,
        public DatabaseClusterType $type,
        public string $region,
        public DatabaseClusterConfiguration $configuration,
        public LogicalDatabaseDefinitionCollection $databases,
    ) {
    }
}
