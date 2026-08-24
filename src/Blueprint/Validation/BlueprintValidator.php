<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Blueprint\Validation;

final readonly class BlueprintValidator
{
    /** @param array<string, mixed> $data */
    public function validate(array $data): ValidationResult
    {
        $errors = [];

        $this->unknownProperties($data, ['version', 'organization', 'application', 'environments'], '', $errors);
        $this->validateVersion($data, $errors);
        $this->requiredNonEmptyString($data, 'organization', 'organization', $errors);
        $this->validateApplication($data, $errors);
        $this->validateEnvironments($data, $errors);

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

        $this->unknownProperties($application, ['name', 'source'], 'application', $errors);
        $this->requiredNonEmptyString($application, 'name', 'application.name', $errors);

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

        if ($source['provider'] !== 'github') {
            $errors[] = $this->error($path, ValidationErrorCode::UNSUPPORTED_PROVIDER, 'Provider is not supported.');
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param list<ValidationError> $errors
     */
    private function validateEnvironments(array $data, array &$errors): void
    {
        $environments = $this->requiredMapping($data, 'environments', 'environments', $errors);

        if ($environments === null) {
            return;
        }

        foreach ($environments as $name => $environment) {
            if ($name === '') {
                $errors[] = $this->error('environments', ValidationErrorCode::EMPTY_VALUE, 'Environment names must be non-empty strings.');
                continue;
            }

            $path = 'environments.' . $name;
            $environment = $this->asMapping($environment, $path, $errors);

            if ($environment === null) {
                continue;
            }

            $this->unknownProperties($environment, ['branch', 'variables'], $path, $errors);
            $this->requiredNonEmptyString($environment, 'branch', $path . '.branch', $errors);
            $this->validateVariables($environment, $path, $errors);
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
            if ($name === '') {
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
            } elseif ($variable['from_env'] === '') {
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
        } elseif ($data[$key] === '') {
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
