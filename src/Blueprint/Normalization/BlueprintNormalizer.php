<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Blueprint\Normalization;

use LaravelCloudBlueprint\Blueprint\ApplicationDefinition;
use LaravelCloudBlueprint\Blueprint\Blueprint;
use LaravelCloudBlueprint\Blueprint\BlueprintSchemaVersion;
use LaravelCloudBlueprint\Blueprint\EnvironmentDefinition;
use LaravelCloudBlueprint\Blueprint\EnvironmentDefinitionCollection;
use LaravelCloudBlueprint\Blueprint\EnvironmentVariableReference;
use LaravelCloudBlueprint\Blueprint\LiteralVariableValue;
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
            );
        }

        return new EnvironmentDefinitionCollection(...$normalized);
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
