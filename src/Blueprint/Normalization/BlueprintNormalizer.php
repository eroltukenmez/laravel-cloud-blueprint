<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Blueprint\Normalization;

use LaravelCloudBlueprint\Blueprint\ApplicationDefinition;
use LaravelCloudBlueprint\Blueprint\Blueprint;
use LaravelCloudBlueprint\Blueprint\BlueprintSchemaVersion;
use LaravelCloudBlueprint\Blueprint\DatabaseClusterConfiguration;
use LaravelCloudBlueprint\Blueprint\DatabaseClusterDefinition;
use LaravelCloudBlueprint\Blueprint\DatabaseClusterDefinitionCollection;
use LaravelCloudBlueprint\Blueprint\DatabaseClusterType;
use LaravelCloudBlueprint\Blueprint\DatabaseReference;
use LaravelCloudBlueprint\Blueprint\EnvironmentDefinition;
use LaravelCloudBlueprint\Blueprint\EnvironmentDefinitionCollection;
use LaravelCloudBlueprint\Blueprint\EnvironmentVariableReference;
use LaravelCloudBlueprint\Blueprint\LiteralVariableValue;
use LaravelCloudBlueprint\Blueprint\LaravelMySqlConfiguration;
use LaravelCloudBlueprint\Blueprint\LogicalDatabaseDefinition;
use LaravelCloudBlueprint\Blueprint\LogicalDatabaseDefinitionCollection;
use LaravelCloudBlueprint\Blueprint\NeonPostgresConfiguration;
use LaravelCloudBlueprint\Blueprint\SourceDefinition;
use LaravelCloudBlueprint\Blueprint\SourceProvider;
use LaravelCloudBlueprint\Blueprint\VariableDefinition;
use LaravelCloudBlueprint\Blueprint\VariableDefinitionCollection;
use LaravelCloudBlueprint\Blueprint\VariableValueSource;

final readonly class BlueprintNormalizer
{
    /** @param array<string, mixed> $data */
    public function normalize(array $data): Blueprint
    {
        $application = $this->mapping($data, 'application');
        $source = $this->mapping($application, 'source', 'application.source');

        return new Blueprint(
            $this->schemaVersion($data),
            $this->string($data, 'organization'),
            new ApplicationDefinition(
                $this->string($application, 'name', 'application.name'),
                $this->string($application, 'region', 'application.region'),
                new SourceDefinition(
                    $this->sourceProvider($source),
                    $this->string($source, 'repository', 'application.source.repository'),
                ),
            ),
            $this->environments($data),
            $this->databaseClusters($data),
        );
    }

    /** @param array<string, mixed> $data */
    private function schemaVersion(array $data): BlueprintSchemaVersion
    {
        $version = $this->integer($data, 'version');

        return BlueprintSchemaVersion::tryFrom($version)
            ?? throw new BlueprintNormalizationException('version', 'Unsupported schema version.');
    }

    /** @param array<string, mixed> $source */
    private function sourceProvider(array $source): SourceProvider
    {
        $provider = $this->string($source, 'provider', 'application.source.provider');

        return SourceProvider::tryFrom($provider)
            ?? throw new BlueprintNormalizationException(
                'application.source.provider',
                'Unsupported source provider.',
            );
    }

    /** @param array<string, mixed> $data */
    private function environments(array $data): EnvironmentDefinitionCollection
    {
        $normalized = [];

        foreach ($this->mapping($data, 'environments') as $name => $environment) {
            $path = sprintf('environments.%s', $name);
            $environment = $this->valueAsMapping($environment, $path);

            $normalized[] = new EnvironmentDefinition(
                $name,
                $this->string($environment, 'branch', $path . '.branch'),
                $this->variables($environment, $path),
                $this->databaseReference($environment, $path),
            );
        }

        return new EnvironmentDefinitionCollection(...$normalized);
    }

    /** @param array<string, mixed> $data */
    private function databaseClusters(array $data): DatabaseClusterDefinitionCollection
    {
        if (!array_key_exists('database_clusters', $data)) {
            return new DatabaseClusterDefinitionCollection();
        }

        $clusters = [];
        foreach ($this->mapping($data, 'database_clusters') as $name => $clusterValue) {
            $path = 'database_clusters.' . $name;
            $cluster = $this->valueAsMapping($clusterValue, $path);
            $typeValue = $this->string($cluster, 'type', $path . '.type');
            $type = DatabaseClusterType::tryFrom($typeValue)
                ?? throw new BlueprintNormalizationException($path . '.type', 'Unsupported Database Cluster type.');

            $clusters[] = new DatabaseClusterDefinition(
                $name,
                $type,
                $this->string($cluster, 'region', $path . '.region'),
                $this->databaseConfiguration($type, $this->mapping($cluster, 'config', $path . '.config'), $path),
                $this->logicalDatabases($cluster, $path),
            );
        }

        return new DatabaseClusterDefinitionCollection(...$clusters);
    }

    /** @param array<string, mixed> $config */
    private function databaseConfiguration(
        DatabaseClusterType $type,
        array $config,
        string $clusterPath,
    ): DatabaseClusterConfiguration {
        $path = $clusterPath . '.config';
        if ($type === DatabaseClusterType::LARAVEL_MYSQL_8) {
            return new LaravelMySqlConfiguration(
                $this->string($config, 'size', $path . '.size'),
                $this->integerAt($config, 'storage', $path . '.storage'),
                $this->integerAt($config, 'retention_days', $path . '.retention_days'),
                $this->boolean($config, 'uses_scheduled_snapshots', $path . '.uses_scheduled_snapshots'),
                $this->boolean($config, 'is_public', $path . '.is_public'),
            );
        }

        return new NeonPostgresConfiguration(
            $this->number($config, 'cu_min', $path . '.cu_min'),
            $this->number($config, 'cu_max', $path . '.cu_max'),
            $this->integerAt($config, 'suspend_seconds', $path . '.suspend_seconds'),
            $this->integerAt($config, 'retention_days', $path . '.retention_days'),
        );
    }

    /** @param array<string, mixed> $cluster */
    private function logicalDatabases(array $cluster, string $clusterPath): LogicalDatabaseDefinitionCollection
    {
        $databases = [];
        foreach ($this->mapping($cluster, 'databases', $clusterPath . '.databases') as $name => $definition) {
            $this->valueAsMapping($definition, $clusterPath . '.databases.' . $name);
            $databases[] = new LogicalDatabaseDefinition($name);
        }

        return new LogicalDatabaseDefinitionCollection(...$databases);
    }

    /** @param array<string, mixed> $environment */
    private function databaseReference(array $environment, string $environmentPath): ?DatabaseReference
    {
        if (!array_key_exists('database', $environment)) {
            return null;
        }

        $reference = $this->string($environment, 'database', $environmentPath . '.database');
        try {
            return DatabaseReference::fromString($reference);
        } catch (\InvalidArgumentException) {
            throw new BlueprintNormalizationException(
                $environmentPath . '.database',
                'Database reference must use <cluster>.<database>.',
            );
        }
    }

    /** @param array<string, mixed> $environment */
    private function variables(array $environment, string $environmentPath): VariableDefinitionCollection
    {
        $variablesPath = $environmentPath . '.variables';
        $normalized = [];

        if (!array_key_exists('variables', $environment)) {
            return new VariableDefinitionCollection();
        }

        foreach ($this->mapping($environment, 'variables', $variablesPath) as $name => $variable) {
            $path = sprintf('%s.%s', $variablesPath, $name);
            $variable = $this->valueAsMapping($variable, $path);

            $normalized[] = new VariableDefinition(
                $name,
                $this->variableValueSource($variable, $path),
                $this->optionalBoolean($variable, 'sensitive', $path . '.sensitive', false),
            );
        }

        return new VariableDefinitionCollection(...$normalized);
    }

    /** @param array<string, mixed> $variable */
    private function variableValueSource(array $variable, string $path): VariableValueSource
    {
        $hasValue = array_key_exists('value', $variable);
        $hasEnvironmentReference = array_key_exists('from_env', $variable);

        if ($hasValue === $hasEnvironmentReference) {
            throw new BlueprintNormalizationException(
                $path,
                $hasValue
                    ? 'A variable cannot contain both value and from_env.'
                    : 'A variable must contain either value or from_env.',
            );
        }

        if ($hasValue) {
            return new LiteralVariableValue($this->string($variable, 'value', $path . '.value'));
        }

        return new EnvironmentVariableReference(
            $this->string($variable, 'from_env', $path . '.from_env'),
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function mapping(array $data, string $key, ?string $path = null): array
    {
        $path ??= $key;

        if (!array_key_exists($key, $data)) {
            throw new BlueprintNormalizationException($path, 'Required mapping is missing.');
        }

        return $this->valueAsMapping($data[$key], $path);
    }

    /** @return array<string, mixed> */
    private function valueAsMapping(mixed $value, string $path): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new BlueprintNormalizationException($path, 'Expected a mapping.');
        }

        $mapping = [];

        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new BlueprintNormalizationException($path, 'Mapping keys must be strings.');
            }

            $mapping[$key] = $item;
        }

        return $mapping;
    }

    /** @param array<string, mixed> $data */
    private function string(array $data, string $key, ?string $path = null): string
    {
        $path ??= $key;

        if (!array_key_exists($key, $data) || !is_string($data[$key])) {
            throw new BlueprintNormalizationException($path, 'Expected a string.');
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private function integer(array $data, string $key): int
    {
        if (!array_key_exists($key, $data) || !is_int($data[$key])) {
            throw new BlueprintNormalizationException($key, 'Expected an integer.');
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private function integerAt(array $data, string $key, string $path): int
    {
        if (!array_key_exists($key, $data) || !is_int($data[$key])) {
            throw new BlueprintNormalizationException($path, 'Expected an integer.');
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private function number(array $data, string $key, string $path): float
    {
        if (!array_key_exists($key, $data) || (!is_int($data[$key]) && !is_float($data[$key]))) {
            throw new BlueprintNormalizationException($path, 'Expected a number.');
        }

        return (float) $data[$key];
    }

    /** @param array<string, mixed> $data */
    private function boolean(array $data, string $key, string $path): bool
    {
        if (!array_key_exists($key, $data) || !is_bool($data[$key])) {
            throw new BlueprintNormalizationException($path, 'Expected a boolean.');
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private function optionalBoolean(array $data, string $key, string $path, bool $default): bool
    {
        if (!array_key_exists($key, $data)) {
            return $default;
        }

        if (!is_bool($data[$key])) {
            throw new BlueprintNormalizationException($path, 'Expected a boolean.');
        }

        return $data[$key];
    }
}
