<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Blueprint\Validation;

final readonly class BlueprintValidator
{
    /** @param array<string, mixed> $data */
    public function validate(array $data): ValidationResult
    {
        $errors = [];

        $this->unknownProperties($data, ['version', 'organization', 'application', 'environments', 'database_clusters'], '', $errors);
        $this->validateVersion($data, $errors);
        $this->requiredNonEmptyString($data, 'organization', 'organization', $errors);
        $this->validateApplication($data, $errors);
        $databases = $this->validateDatabaseClusters($data, $errors);
        $this->validateEnvironments($data, $databases, $errors);

        return new ValidationResult(...$errors);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<ValidationError> $errors
     */
    private function validateVersion(array $data, array &$errors): void
    {
        if (!$this->required($data, 'version', 'version', $errors)) {
            return;
        }

        if (!is_int($data['version'])) {
            $errors[] = $this->error('version', ValidationErrorCode::INVALID_TYPE, 'Version must be an integer.');
            return;
        }

        if ($data['version'] !== 1) {
            $errors[] = $this->error('version', ValidationErrorCode::UNSUPPORTED_VERSION, 'Version is not supported.');
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param list<ValidationError> $errors
     */
    private function validateApplication(array $data, array &$errors): void
    {
        $application = $this->requiredMapping($data, 'application', 'application', $errors);

        if ($application === null) {
            return;
        }

        $this->unknownProperties($application, ['name', 'region', 'source'], 'application', $errors);
        $this->requiredNonEmptyString($application, 'name', 'application.name', $errors);
        $this->requiredNonEmptyString($application, 'region', 'application.region', $errors);

        $source = $this->requiredMapping($application, 'source', 'application.source', $errors);

        if ($source === null) {
            return;
        }

        $this->unknownProperties($source, ['provider', 'repository'], 'application.source', $errors);
        $this->validateProvider($source, $errors);
        $this->requiredNonEmptyString($source, 'repository', 'application.source.repository', $errors);
    }

    /**
     * @param array<string, mixed> $source
     * @param list<ValidationError> $errors
     */
    private function validateProvider(array $source, array &$errors): void
    {
        $path = 'application.source.provider';

        if (!$this->required($source, 'provider', $path, $errors)) {
            return;
        }

        if (!is_string($source['provider'])) {
            $errors[] = $this->error($path, ValidationErrorCode::INVALID_TYPE, 'Provider must be a string.');
            return;
        }

        if (!in_array($source['provider'], ['github', 'gitlab', 'bitbucket'], true)) {
            $errors[] = $this->error($path, ValidationErrorCode::UNSUPPORTED_PROVIDER, 'Provider is not supported.');
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, array<string, true>> $databases
     * @param list<ValidationError> $errors
     */
    private function validateEnvironments(array $data, array $databases, array &$errors): void
    {
        $environments = $this->requiredMapping($data, 'environments', 'environments', $errors);

        if ($environments === null) {
            return;
        }

        foreach ($environments as $name => $environment) {
            if (trim($name) === '') {
                $errors[] = $this->error('environments', ValidationErrorCode::EMPTY_VALUE, 'Environment names must be non-empty strings.');
                continue;
            }

            $path = 'environments.' . $name;
            $environment = $this->asMapping($environment, $path, $errors);

            if ($environment === null) {
                continue;
            }

            $this->unknownProperties($environment, ['branch', 'variables', 'database'], $path, $errors);
            $this->requiredNonEmptyString($environment, 'branch', $path . '.branch', $errors);
            $this->validateVariables($environment, $path, $errors);
            $this->validateDatabaseReference($environment, $path, $databases, $errors);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param list<ValidationError> $errors
     * @return array<string, array<string, true>>
     */
    private function validateDatabaseClusters(array $data, array &$errors): array
    {
        if (!array_key_exists('database_clusters', $data)) {
            return [];
        }

        $clusters = $this->asMapping($data['database_clusters'], 'database_clusters', $errors);
        if ($clusters === null) {
            return [];
        }

        $references = [];
        foreach ($clusters as $name => $clusterValue) {
            $path = 'database_clusters.' . $name;
            if (trim($name) === '' || str_contains($name, '.')) {
                $errors[] = $this->error(
                    'database_clusters',
                    ValidationErrorCode::EMPTY_VALUE,
                    'Database Cluster logical names must be non-empty and must not contain dots.',
                );
                continue;
            }

            $cluster = $this->asMapping($clusterValue, $path, $errors);
            if ($cluster === null) {
                continue;
            }
            $this->unknownProperties($cluster, ['type', 'region', 'config', 'databases'], $path, $errors);
            $type = $this->validateDatabaseClusterType($cluster, $path, $errors);
            $this->requiredNonEmptyString($cluster, 'region', $path . '.region', $errors);
            $this->validateDatabaseConfiguration($cluster, $path, $type, $errors);
            $references[$name] = $this->validateLogicalDatabases($cluster, $path, $errors);
        }

        return $references;
    }

    /**
     * @param array<string, mixed> $cluster
     * @param list<ValidationError> $errors
     */
    private function validateDatabaseClusterType(array $cluster, string $path, array &$errors): ?string
    {
        $typePath = $path . '.type';
        if (!$this->required($cluster, 'type', $typePath, $errors)) {
            return null;
        }
        if (!is_string($cluster['type'])) {
            $errors[] = $this->error($typePath, ValidationErrorCode::INVALID_TYPE, 'Database Cluster type must be a string.');
            return null;
        }

        $supported = ['laravel_mysql_8', 'neon_serverless_postgres_18', 'neon_serverless_postgres_17'];
        if (!in_array($cluster['type'], $supported, true)) {
            $errors[] = $this->error(
                $typePath,
                ValidationErrorCode::UNSUPPORTED_DATABASE_TYPE,
                'Database Cluster type is not supported.',
            );
            return null;
        }

        return $cluster['type'];
    }

    /**
     * @param array<string, mixed> $cluster
     * @param list<ValidationError> $errors
     */
    private function validateDatabaseConfiguration(
        array $cluster,
        string $clusterPath,
        ?string $type,
        array &$errors,
    ): void {
        $path = $clusterPath . '.config';
        $config = $this->requiredMapping($cluster, 'config', $path, $errors);
        if ($config === null || $type === null) {
            return;
        }

        if ($type === 'laravel_mysql_8') {
            $this->unknownProperties(
                $config,
                ['size', 'storage', 'retention_days', 'uses_scheduled_snapshots', 'is_public'],
                $path,
                $errors,
            );
            $this->requiredNonEmptyString($config, 'size', $path . '.size', $errors);
            $this->requiredNonNegativeInteger($config, 'storage', $path . '.storage', $errors, positive: true);
            $this->requiredNonNegativeInteger($config, 'retention_days', $path . '.retention_days', $errors);
            $this->requiredBoolean($config, 'uses_scheduled_snapshots', $path . '.uses_scheduled_snapshots', $errors);
            $this->requiredBoolean($config, 'is_public', $path . '.is_public', $errors);
            return;
        }

        $this->unknownProperties($config, ['cu_min', 'cu_max', 'suspend_seconds', 'retention_days'], $path, $errors);
        $minimum = $this->requiredNonNegativeNumber($config, 'cu_min', $path . '.cu_min', $errors, positive: true);
        $maximum = $this->requiredNonNegativeNumber($config, 'cu_max', $path . '.cu_max', $errors, positive: true);
        $this->requiredNonNegativeInteger($config, 'suspend_seconds', $path . '.suspend_seconds', $errors);
        $this->requiredNonNegativeInteger($config, 'retention_days', $path . '.retention_days', $errors);
        if ($minimum !== null && $maximum !== null && $minimum > $maximum) {
            $errors[] = $this->error($path . '.cu_max', ValidationErrorCode::INVALID_TYPE, 'cu_max must be greater than or equal to cu_min.');
        }
    }

    /**
     * @param array<string, mixed> $cluster
     * @param list<ValidationError> $errors
     * @return array<string, true>
     */
    private function validateLogicalDatabases(array $cluster, string $clusterPath, array &$errors): array
    {
        $path = $clusterPath . '.databases';
        $databases = $this->requiredMapping($cluster, 'databases', $path, $errors);
        if ($databases === null) {
            return [];
        }

        $references = [];
        foreach ($databases as $name => $definitionValue) {
            if (trim($name) === '' || str_contains($name, '.')) {
                $errors[] = $this->error(
                    $path,
                    ValidationErrorCode::EMPTY_VALUE,
                    'Logical Database names must be non-empty and must not contain dots.',
                );
                continue;
            }
            $definitionPath = $path . '.' . $name;
            $definition = $this->asMapping($definitionValue, $definitionPath, $errors);
            if ($definition === null) {
                continue;
            }
            $this->unknownProperties($definition, [], $definitionPath, $errors);
            $references[$name] = true;
        }

        return $references;
    }

    /**
     * @param array<string, mixed> $environment
     * @param array<string, array<string, true>> $databases
     * @param list<ValidationError> $errors
     */
    private function validateDatabaseReference(
        array $environment,
        string $environmentPath,
        array $databases,
        array &$errors,
    ): void {
        if (!array_key_exists('database', $environment)) {
            return;
        }

        $path = $environmentPath . '.database';
        if (!is_string($environment['database'])) {
            $errors[] = $this->error($path, ValidationErrorCode::INVALID_TYPE, 'Database reference must be a string.');
            return;
        }
        $parts = explode('.', $environment['database']);
        if (count($parts) !== 2 || trim($parts[0]) === '' || trim($parts[1]) === '') {
            $errors[] = $this->error(
                $path,
                ValidationErrorCode::INVALID_DATABASE_REFERENCE,
                'Database reference must use <cluster>.<database>.',
            );
            return;
        }
        if (!array_key_exists($parts[0], $databases)) {
            $errors[] = $this->error($path, ValidationErrorCode::INVALID_DATABASE_REFERENCE, 'Referenced Database Cluster does not exist.');
            return;
        }
        if (!isset($databases[$parts[0]][$parts[1]])) {
            $errors[] = $this->error($path, ValidationErrorCode::INVALID_DATABASE_REFERENCE, 'Referenced logical Database does not exist.');
        }
    }

    /**
     * @param array<string, mixed> $environment
     * @param list<ValidationError> $errors
     */
    private function validateVariables(array $environment, string $environmentPath, array &$errors): void
    {
        if (!array_key_exists('variables', $environment)) {
            return;
        }

        $path = $environmentPath . '.variables';
        $variables = $this->asMapping($environment['variables'], $path, $errors);

        if ($variables === null) {
            return;
        }

        foreach ($variables as $name => $variable) {
            if (trim($name) === '') {
                $errors[] = $this->error($path, ValidationErrorCode::EMPTY_VALUE, 'Variable names must be non-empty strings.');
                continue;
            }

            $variablePath = $path . '.' . $name;
            $variable = $this->asMapping($variable, $variablePath, $errors);

            if ($variable === null) {
                continue;
            }

            $this->unknownProperties($variable, ['value', 'from_env', 'sensitive'], $variablePath, $errors);
            $this->validateVariableSource($variable, $variablePath, $errors);
            $this->optionalBoolean($variable, 'sensitive', $variablePath . '.sensitive', $errors);
        }
    }

    /**
     * @param array<string, mixed> $variable
     * @param list<ValidationError> $errors
     */
    private function validateVariableSource(array $variable, string $path, array &$errors): void
    {
        $hasValue = array_key_exists('value', $variable);
        $hasReference = array_key_exists('from_env', $variable);

        if ($hasValue === $hasReference) {
            $errors[] = $this->error(
                $path,
                ValidationErrorCode::INVALID_VARIABLE_SOURCE,
                $hasValue
                    ? 'Variable must not define both value and from_env.'
                    : 'Variable must define either value or from_env.',
            );
            return;
        }

        if ($hasValue && !is_string($variable['value'])) {
            $errors[] = $this->error($path . '.value', ValidationErrorCode::INVALID_TYPE, 'Value must be a string.');
        }

        if ($hasReference) {
            if (!is_string($variable['from_env'])) {
                $errors[] = $this->error($path . '.from_env', ValidationErrorCode::INVALID_TYPE, 'from_env must be a string.');
            } elseif (trim($variable['from_env']) === '') {
                $errors[] = $this->error($path . '.from_env', ValidationErrorCode::EMPTY_VALUE, 'from_env must not be empty.');
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param list<ValidationError> $errors
     */
    private function requiredNonEmptyString(array $data, string $key, string $path, array &$errors): void
    {
        if (!$this->required($data, $key, $path, $errors)) {
            return;
        }

        if (!is_string($data[$key])) {
            $errors[] = $this->error($path, ValidationErrorCode::INVALID_TYPE, 'Value must be a string.');
        } elseif (trim($data[$key]) === '') {
            $errors[] = $this->error($path, ValidationErrorCode::EMPTY_VALUE, 'Value must not be empty.');
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param list<ValidationError> $errors
     */
    private function optionalBoolean(array $data, string $key, string $path, array &$errors): void
    {
        if (array_key_exists($key, $data) && !is_bool($data[$key])) {
            $errors[] = $this->error($path, ValidationErrorCode::INVALID_TYPE, 'Value must be a boolean.');
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param list<ValidationError> $errors
     */
    private function requiredBoolean(array $data, string $key, string $path, array &$errors): void
    {
        if (!$this->required($data, $key, $path, $errors)) {
            return;
        }
        if (!is_bool($data[$key])) {
            $errors[] = $this->error($path, ValidationErrorCode::INVALID_TYPE, 'Value must be a boolean.');
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param list<ValidationError> $errors
     */
    private function requiredNonNegativeInteger(
        array $data,
        string $key,
        string $path,
        array &$errors,
        bool $positive = false,
    ): void {
        if (!$this->required($data, $key, $path, $errors)) {
            return;
        }
        if (!is_int($data[$key]) || ($positive ? $data[$key] <= 0 : $data[$key] < 0)) {
            $errors[] = $this->error(
                $path,
                ValidationErrorCode::INVALID_TYPE,
                $positive ? 'Value must be a positive integer.' : 'Value must be a non-negative integer.',
            );
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param list<ValidationError> $errors
     */
    private function requiredNonNegativeNumber(
        array $data,
        string $key,
        string $path,
        array &$errors,
        bool $positive = false,
    ): ?float {
        if (!$this->required($data, $key, $path, $errors)) {
            return null;
        }
        $value = $data[$key];
        if ((!is_int($value) && !is_float($value)) || ($positive ? $value <= 0 : $value < 0)) {
            $errors[] = $this->error(
                $path,
                ValidationErrorCode::INVALID_TYPE,
                $positive ? 'Value must be a positive number.' : 'Value must be a non-negative number.',
            );
            return null;
        }

        return (float) $value;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<ValidationError> $errors
     */
    private function required(array $data, string $key, string $path, array &$errors): bool
    {
        if (array_key_exists($key, $data)) {
            return true;
        }

        $errors[] = $this->error($path, ValidationErrorCode::REQUIRED, 'Required property is missing.');

        return false;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<ValidationError> $errors
     * @return array<string, mixed>|null
     */
    private function requiredMapping(array $data, string $key, string $path, array &$errors): ?array
    {
        if (!$this->required($data, $key, $path, $errors)) {
            return null;
        }

        return $this->asMapping($data[$key], $path, $errors);
    }

    /**
     * @param list<ValidationError> $errors
     * @return array<string, mixed>|null
     */
    private function asMapping(mixed $value, string $path, array &$errors): ?array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            $errors[] = $this->error($path, ValidationErrorCode::INVALID_TYPE, 'Value must be a mapping.');
            return null;
        }

        $mapping = [];

        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                $errors[] = $this->error($path, ValidationErrorCode::INVALID_TYPE, 'Mapping keys must be strings.');
                continue;
            }

            $mapping[$key] = $item;
        }

        return $mapping;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $allowed
     * @param list<ValidationError> $errors
     */
    private function unknownProperties(array $data, array $allowed, string $parentPath, array &$errors): void
    {
        foreach ($data as $property => $_value) {
            if (!in_array($property, $allowed, true)) {
                $path = $parentPath === '' ? $property : $parentPath . '.' . $property;
                $errors[] = $this->error($path, ValidationErrorCode::UNKNOWN_PROPERTY, 'Property is not recognized.');
            }
        }
    }

    private function error(string $path, ValidationErrorCode $code, string $message): ValidationError
    {
        return new ValidationError($path, $code, $message);
    }
}
