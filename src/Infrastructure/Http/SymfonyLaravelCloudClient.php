<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Infrastructure\Http;

use LaravelCloudBlueprint\Cloud\CloudApiToken;
use LaravelCloudBlueprint\Blueprint\SourceProvider;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudDatabaseMutationClient;
use LaravelCloudBlueprint\Cloud\DTO\CloudApplication;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabase;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabaseCluster;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabaseClusterConfiguration;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironment;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironmentDetails;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironmentVariable;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironmentVariableCollection;
use LaravelCloudBlueprint\Cloud\DTO\CloudOrganization;
use LaravelCloudBlueprint\Cloud\DTO\CloudLaravelMySqlConfiguration;
use LaravelCloudBlueprint\Cloud\DTO\CloudNeonPostgresConfiguration;
use LaravelCloudBlueprint\Cloud\DTO\CloudUnknownDatabaseConfiguration;
use LaravelCloudBlueprint\Cloud\DTO\CreateApplicationRequest;
use LaravelCloudBlueprint\Cloud\DTO\CreateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\DTO\CreateDatabaseClusterRequest;
use LaravelCloudBlueprint\Cloud\DTO\CreateDatabaseRequest;
use LaravelCloudBlueprint\Cloud\DTO\EnvironmentVariableInput;
use LaravelCloudBlueprint\Cloud\DTO\SetEnvironmentVariablesRequest;
use LaravelCloudBlueprint\Cloud\DTO\UpdateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\DTO\UpdatedCloudEnvironment;
use LaravelCloudBlueprint\Cloud\Exception\CloudApiException;
use LaravelCloudBlueprint\Cloud\Exception\CloudAuthenticationException;
use LaravelCloudBlueprint\Cloud\Exception\CloudRateLimitException;
use LaravelCloudBlueprint\Cloud\Exception\CloudResourceNotFoundException;
use LaravelCloudBlueprint\Cloud\Exception\CloudResponseException;
use LaravelCloudBlueprint\Cloud\Exception\CloudTransportException;
use LaravelCloudBlueprint\Cloud\Exception\CloudValidationException;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final readonly class SymfonyLaravelCloudClient implements LaravelCloudDatabaseMutationClient
{
    private const string BASE_URL = 'https://cloud.laravel.com/api';
    private const string USER_AGENT = 'Laravel-Cloud-Blueprint/0.1.0-alpha.4';

    public function __construct(
        private HttpClientInterface $http,
        private CloudApiToken $token,
    ) {
    }

    public function organization(): CloudOrganization
    {
        $path = '/meta/organization';
        $document = $this->get($path);
        $resource = $this->mappingAt($document, 'data', $path);
        $attributes = $this->mappingAt($resource, 'attributes', $path);

        return new CloudOrganization(
            $this->requiredString($resource, 'id', $path),
            $this->requiredString($attributes, 'name', $path),
            $this->requiredString($attributes, 'slug', $path),
        );
    }

    public function applications(): array
    {
        $applications = [];

        foreach ($this->pages('/applications') as [$document, $path]) {
            foreach ($this->listAt($document, 'data', $path) as $resource) {
                $resource = $this->valueAsMapping($resource, $path);
                $attributes = $this->mappingAt($resource, 'attributes', $path);
                $repository = $this->optionalMapping($attributes, 'repository', $path);

                $applications[] = new CloudApplication(
                    $this->requiredString($resource, 'id', $path),
                    $this->requiredString($attributes, 'name', $path),
                    $this->optionalString($attributes, 'slug', $path),
                    $this->requiredString($attributes, 'region', $path),
                    $repository === null ? null : $this->requiredString($repository, 'full_name', $path),
                    $this->sourceProvider($attributes, $path),
                );
            }
        }

        return $applications;
    }

    public function environments(string $applicationId): array
    {
        $environments = [];
        $initialPath = sprintf('/applications/%s/environments?include=branch,database', rawurlencode($applicationId));

        foreach ($this->pages($initialPath) as [$document, $path]) {
            foreach ($this->listAt($document, 'data', $path) as $resource) {
                $resource = $this->valueAsMapping($resource, $path);
                $attributes = $this->mappingAt($resource, 'attributes', $path);

                $environments[] = new CloudEnvironment(
                    $this->requiredString($resource, 'id', $path),
                    $applicationId,
                    $this->requiredString($attributes, 'name', $path),
                    $this->branch($resource, $document, $path),
                    $this->databaseRelationshipId($resource, $path),
                );
            }
        }

        return $environments;
    }

    public function environment(string $environmentId): CloudEnvironmentDetails
    {
        $path = sprintf('/environments/%s?include=database', rawurlencode($environmentId));
        $document = $this->get($path);
        $resource = $this->mappingAt($document, 'data', $path);
        $id = $this->requiredString($resource, 'id', $path);
        if ($id !== $environmentId) {
            throw $this->malformed($path, 'Environment response identity does not match the requested environment.');
        }
        $attributes = $this->mappingAt($resource, 'attributes', $path);

        return new CloudEnvironmentDetails(
            $id,
            $this->requiredString($attributes, 'name', $path),
            $this->environmentVariables($attributes, $path),
            $this->databaseRelationshipId($resource, $path),
        );
    }

    public function databaseClusters(): array
    {
        $clusters = [];
        $seen = [];

        foreach ($this->pages('/databases/clusters') as [$document, $path]) {
            foreach ($this->listAt($document, 'data', $path) as $value) {
                $cluster = $this->databaseClusterFromResource($this->valueAsMapping($value, $path), $path);
                if (isset($seen[$cluster->id])) {
                    throw $this->malformed($path, 'Database Cluster response contains duplicate IDs.');
                }
                $seen[$cluster->id] = true;
                $clusters[] = $cluster;
            }
        }

        usort($clusters, static fn (CloudDatabaseCluster $left, CloudDatabaseCluster $right): int => $left->id <=> $right->id);
        return $clusters;
    }

    public function databaseCluster(string $clusterId): CloudDatabaseCluster
    {
        $path = sprintf('/databases/clusters/%s', rawurlencode($clusterId));
        $resource = $this->mappingAt($this->get($path), 'data', $path);
        $cluster = $this->databaseClusterFromResource($resource, $path);
        if ($cluster->id !== $clusterId) {
            throw $this->malformed($path, 'Database Cluster response identity does not match the requested Cluster.');
        }

        return $cluster;
    }

    public function databases(string $clusterId): array
    {
        $databases = [];
        $seen = [];
        $initialPath = sprintf('/databases/clusters/%s/databases', rawurlencode($clusterId));

        foreach ($this->pages($initialPath) as [$document, $path]) {
            foreach ($this->listAt($document, 'data', $path) as $value) {
                $database = $this->databaseFromResource($this->valueAsMapping($value, $path), $clusterId, $path);
                if (isset($seen[$database->id])) {
                    throw $this->malformed($path, 'Logical Database response contains duplicate IDs.');
                }
                $seen[$database->id] = true;
                $databases[] = $database;
            }
        }

        usort($databases, static fn (CloudDatabase $left, CloudDatabase $right): int => $left->id <=> $right->id);
        return $databases;
    }

    public function database(string $clusterId, string $databaseId): CloudDatabase
    {
        $path = sprintf(
            '/databases/clusters/%s/databases/%s',
            rawurlencode($clusterId),
            rawurlencode($databaseId),
        );
        $resource = $this->mappingAt($this->get($path), 'data', $path);
        $database = $this->databaseFromResource($resource, $clusterId, $path);
        if ($database->id !== $databaseId) {
            throw $this->malformed($path, 'Logical Database response identity does not match the requested Database.');
        }

        return $database;
    }

    public function createDatabaseCluster(CreateDatabaseClusterRequest $request): CloudDatabaseCluster
    {
        $path = '/databases/clusters';
        $document = $this->post($path, [
            'type' => $request->type,
            'name' => $request->name,
            'region' => $request->region,
            'config' => $request->configuration->payload(),
        ]);

        return $this->databaseClusterFromResource($this->mappingAt($document, 'data', $path), $path);
    }

    public function createDatabase(string $clusterId, CreateDatabaseRequest $request): CloudDatabase
    {
        $path = sprintf('/databases/clusters/%s/databases', rawurlencode($clusterId));
        $document = $this->post($path, ['name' => $request->name]);

        return $this->databaseFromResource($this->mappingAt($document, 'data', $path), $clusterId, $path);
    }

    public function createApplication(CreateApplicationRequest $request): CloudApplication
    {
        $path = '/applications';
        $document = $this->post($path, [
            'repository' => $request->repository,
            'name' => $request->name,
            'region' => $request->region,
            'source_control_provider_type' => $request->sourceProvider->value,
        ]);
        $resource = $this->mappingAt($document, 'data', $path);
        $attributes = $this->mappingAt($resource, 'attributes', $path);
        $repository = $this->optionalMapping($attributes, 'repository', $path);

        return new CloudApplication(
            $this->requiredString($resource, 'id', $path),
            $this->requiredString($attributes, 'name', $path),
            $this->optionalString($attributes, 'slug', $path),
            $this->requiredString($attributes, 'region', $path),
            $repository === null ? null : $this->requiredString($repository, 'full_name', $path),
            $this->sourceProvider($attributes, $path),
        );
    }

    /** @param array<string, mixed> $attributes */
    private function sourceProvider(array $attributes, string $path): ?SourceProvider
    {
        $value = $this->optionalString($attributes, 'source_control_provider_type', $path);

        return $value === null ? null : SourceProvider::tryFrom($value);
    }

    public function createEnvironment(
        string $applicationId,
        CreateEnvironmentRequest $request,
    ): CloudEnvironment {
        $path = sprintf('/applications/%s/environments', rawurlencode($applicationId));
        $document = $this->post($path, ['branch' => $request->branch, 'name' => $request->name]);
        $resource = $this->mappingAt($document, 'data', $path);
        $attributes = $this->mappingAt($resource, 'attributes', $path);

        return new CloudEnvironment(
            $this->requiredString($resource, 'id', $path),
            $applicationId,
            $this->requiredString($attributes, 'name', $path),
            $request->branch,
        );
    }

    public function updateEnvironment(
        string $environmentId,
        UpdateEnvironmentRequest $request,
    ): UpdatedCloudEnvironment {
        $path = sprintf('/environments/%s', rawurlencode($environmentId));
        $document = $this->patch($path, ['branch' => $request->branch]);
        $resource = $this->mappingAt($document, 'data', $path);
        $id = $this->requiredString($resource, 'id', $path);
        if ($id !== $environmentId) {
            throw new CloudResponseException(
                'Environment update response identity does not match the requested environment.',
                'PATCH',
                $path,
            );
        }
        return new UpdatedCloudEnvironment($id);
    }

    public function setEnvironmentVariables(
        string $environmentId,
        SetEnvironmentVariablesRequest $request,
    ): void {
        $path = sprintf('/environments/%s/variables', rawurlencode($environmentId));
        $variables = array_map(
            static fn (EnvironmentVariableInput $variable): array => [
                'key' => $variable->key,
                'value' => $variable->value,
            ],
            $request->variables(),
        );

        try {
            $response = $this->http->request('POST', self::BASE_URL . $path, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token->value(),
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'User-Agent' => self::USER_AGENT,
                ],
                'json' => [
                    'method' => $request->method->value,
                    'variables' => $variables,
                ],
            ]);
            $status = $response->getStatusCode();
            $requestId = $this->requestId($response);
        } catch (TransportExceptionInterface) {
            throw new CloudTransportException(
                'Laravel Cloud variable request failed with an uncertain remote outcome. Run plan before retrying.',
                'POST',
                $path,
            );
        }

        $this->guardStatus(
            $status,
            $path,
            $requestId,
            'POST',
            $response,
            array_map(static fn (EnvironmentVariableInput $variable): string => $variable->value, $request->variables()),
        );
        if ($status !== 200) {
            throw new CloudResponseException(
                'Laravel Cloud variable response did not return HTTP 200.',
                'POST',
                $path,
                $status,
                $requestId,
            );
        }
    }

    /**
     * @return iterable<array{array<string, mixed>, string}>
     */
    private function pages(string $initialPath): iterable
    {
        $path = $initialPath;

        while (true) {
            $document = $this->get($path);
            yield [$document, $path];

            $links = $this->mappingAt($document, 'links', $path);
            $next = $this->optionalString($links, 'next', $path);

            if ($next === null) {
                return;
            }

            $path = $next;
        }
    }

    /** @return array<string, mixed> */
    private function get(string $path): array
    {
        if (str_starts_with($path, 'https://') && !str_starts_with($path, self::BASE_URL . '/')) {
            throw $this->malformed($path, 'Pagination URL does not belong to Laravel Cloud.');
        }

        $url = str_starts_with($path, 'https://') ? $path : self::BASE_URL . $path;

        try {
            $response = $this->http->request('GET', $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token->value(),
                    'Accept' => 'application/json',
                    'User-Agent' => self::USER_AGENT,
                ],
            ]);
            $status = $response->getStatusCode();
            $requestId = $this->requestId($response);
        } catch (TransportExceptionInterface $exception) {
            throw new CloudTransportException('Unable to connect to Laravel Cloud.', 'GET', $this->safePath($path));
        }

        $this->guardStatus($status, $path, $requestId, response: $response);

        try {
            $data = $response->toArray(false);
        } catch (DecodingExceptionInterface) {
            throw new CloudResponseException(
                'Laravel Cloud returned an invalid JSON response.',
                'GET',
                $this->safePath($path),
                $status,
                $requestId,
            );
        } catch (TransportExceptionInterface) {
            throw new CloudTransportException(
                'Unable to read the Laravel Cloud response.',
                'GET',
                $this->safePath($path),
                $status,
                $requestId,
            );
        }

        return $this->valueAsMapping($data, $path);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        try {
            $response = $this->http->request('POST', self::BASE_URL . $path, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token->value(),
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'User-Agent' => self::USER_AGENT,
                ],
                'json' => $payload,
            ]);
            $status = $response->getStatusCode();
            $requestId = $this->requestId($response);
        } catch (TransportExceptionInterface) {
            throw new CloudTransportException(
                'Laravel Cloud create request failed with an uncertain remote outcome. Run plan before retrying.',
                'POST',
                $path,
            );
        }

        $sensitiveValues = array_values(array_filter($payload, is_string(...)));
        $this->guardStatus($status, $path, $requestId, 'POST', $response, $sensitiveValues);

        if ($status !== 201) {
            throw new CloudResponseException('Laravel Cloud create response did not return HTTP 201.', 'POST', $path, $status, $requestId);
        }

        try {
            return $this->valueAsMapping($response->toArray(false), $path);
        } catch (DecodingExceptionInterface) {
            throw new CloudResponseException('Laravel Cloud returned an invalid JSON response.', 'POST', $path, $status, $requestId);
        } catch (TransportExceptionInterface) {
            throw new CloudTransportException(
                'Laravel Cloud create response could not be read; the remote outcome is uncertain. Run plan before retrying.',
                'POST',
                $path,
                $status,
                $requestId,
            );
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function patch(string $path, array $payload): array
    {
        try {
            $response = $this->http->request('PATCH', self::BASE_URL . $path, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token->value(),
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'User-Agent' => self::USER_AGENT,
                ],
                'json' => $payload,
            ]);
            $status = $response->getStatusCode();
            $requestId = $this->requestId($response);
        } catch (TransportExceptionInterface) {
            throw new CloudTransportException(
                'Laravel Cloud update request failed with an uncertain remote outcome. Run plan before retrying.',
                'PATCH',
                $path,
            );
        }

        $this->guardStatus($status, $path, $requestId, 'PATCH', $response);
        if ($status !== 200) {
            throw new CloudResponseException(
                'Laravel Cloud update response did not return HTTP 200.',
                'PATCH',
                $path,
                $status,
                $requestId,
            );
        }

        try {
            return $this->valueAsMapping($response->toArray(false), $path);
        } catch (DecodingExceptionInterface) {
            throw new CloudResponseException(
                'Laravel Cloud returned an invalid JSON response.',
                'PATCH',
                $path,
                $status,
                $requestId,
            );
        } catch (TransportExceptionInterface) {
            throw new CloudTransportException(
                'Laravel Cloud update response could not be read; the remote outcome is uncertain. Run plan before retrying.',
                'PATCH',
                $path,
                $status,
                $requestId,
            );
        }
    }

    /** @param list<string> $sensitiveValues */
    private function guardStatus(
        int $status,
        string $path,
        ?string $requestId,
        string $method = 'GET',
        ?ResponseInterface $response = null,
        array $sensitiveValues = [],
    ): void {
        if ($status >= 200 && $status < 300) {
            return;
        }

        $context = [$method, $this->safePath($path), $status, $requestId];

        throw match (true) {
            $status === 401 || $status === 403 => new CloudAuthenticationException('Laravel Cloud authentication or authorization failed.', ...$context),
            $status === 404 => new CloudResourceNotFoundException('The requested Laravel Cloud resource was not found.', ...$context),
            $status === 429 => new CloudRateLimitException('Laravel Cloud API rate limit exceeded.', ...$context),
            $status === 422 => $this->validationException($response, $method, $path, $status, $requestId, $sensitiveValues),
            $status >= 500 => new CloudApiException('Laravel Cloud API is unavailable.', ...$context),
            default => new CloudApiException('Laravel Cloud API request failed.', ...$context),
        };
    }

    /**
     * @param list<string> $sensitiveValues
     */
    private function validationException(
        ?ResponseInterface $response,
        string $method,
        string $path,
        int $status,
        ?string $requestId,
        array $sensitiveValues,
    ): CloudValidationException {
        $fallback = new CloudValidationException(null, [], $method, $this->safePath($path), $status, $requestId);
        if ($response === null) {
            return $fallback;
        }

        try {
            $payload = $response->toArray(false);
        } catch (DecodingExceptionInterface|TransportExceptionInterface) {
            return $fallback;
        }

        if (!isset($payload['message'], $payload['errors'])
            || !is_string($payload['message'])
            || !is_array($payload['errors'])
            || ($payload['errors'] !== [] && array_is_list($payload['errors']))) {
            return $fallback;
        }

        $redactions = [$this->token->value(), ...$sensitiveValues];
        $fieldErrors = [];
        foreach ($payload['errors'] as $field => $messages) {
            if (!is_string($field) || !is_array($messages) || !array_is_list($messages) || $messages === []) {
                return $fallback;
            }

            $safeMessages = [];
            foreach ($messages as $message) {
                if (!is_string($message)) {
                    return $fallback;
                }
                $safeMessages[] = $this->redact($message, $redactions);
            }
            $fieldErrors[$this->redact($field, $redactions)] = $safeMessages;
        }

        return new CloudValidationException(
            $this->redact($payload['message'], $redactions),
            $fieldErrors,
            $method,
            $this->safePath($path),
            $status,
            $requestId,
        );
    }

    /** @param list<string> $values */
    private function redact(string $message, array $values): string
    {
        foreach (array_unique($values) as $value) {
            if ($value !== '') {
                $message = str_replace($value, '[redacted]', $message);
            }
        }

        return $message;
    }

    private function requestId(ResponseInterface $response): ?string
    {
        try {
            $headers = $response->getHeaders(false);
        } catch (TransportExceptionInterface) {
            return null;
        }

        foreach (['x-request-id', 'laravel-cloud-request-id'] as $name) {
            if (isset($headers[$name][0])) {
                return $headers[$name][0];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $resource */
    private function databaseClusterFromResource(array $resource, string $path): CloudDatabaseCluster
    {
        $attributes = $this->mappingAt($resource, 'attributes', $path);
        $type = $this->requiredNonEmptyString($attributes, 'type', $path);

        return new CloudDatabaseCluster(
            $this->requiredNonEmptyString($resource, 'id', $path),
            $this->requiredNonEmptyString($attributes, 'name', $path),
            $type,
            $this->requiredNonEmptyString($attributes, 'status', $path),
            $this->requiredNonEmptyString($attributes, 'region', $path),
            $this->databaseConfiguration($type, $attributes, $path),
        );
    }

    /** @param array<string, mixed> $attributes */
    private function databaseConfiguration(
        string $type,
        array $attributes,
        string $path,
    ): CloudDatabaseClusterConfiguration {
        if (!in_array($type, ['laravel_mysql_8', 'neon_serverless_postgres_18', 'neon_serverless_postgres_17'], true)) {
            return new CloudUnknownDatabaseConfiguration();
        }

        $config = $this->mappingAt($attributes, 'config', $path);
        if ($type === 'laravel_mysql_8') {
            return new CloudLaravelMySqlConfiguration(
                $this->requiredNonEmptyString($config, 'size', $path),
                $this->requiredInteger($config, 'storage', $path),
                $this->requiredInteger($config, 'retention_days', $path),
                $this->requiredBoolean($config, 'uses_scheduled_snapshots', $path),
                $this->requiredBoolean($config, 'is_public', $path),
            );
        }

        return new CloudNeonPostgresConfiguration(
            $this->requiredNumber($config, 'cu_min', $path),
            $this->requiredNumber($config, 'cu_max', $path),
            $this->requiredInteger($config, 'suspend_seconds', $path),
            $this->requiredInteger($config, 'retention_days', $path),
        );
    }

    /** @param array<string, mixed> $resource */
    private function databaseFromResource(array $resource, string $clusterId, string $path): CloudDatabase
    {
        return new CloudDatabase(
            $this->requiredNonEmptyString($resource, 'id', $path),
            $clusterId,
            $this->requiredNonEmptyString($this->mappingAt($resource, 'attributes', $path), 'name', $path),
        );
    }

    /** @param array<string, mixed> $resource */
    private function databaseRelationshipId(array $resource, string $path): ?string
    {
        $relationships = $this->optionalMapping($resource, 'relationships', $path);
        if ($relationships === null || !array_key_exists('database', $relationships)) {
            return null;
        }

        $relationship = $this->valueAsMapping($relationships['database'], $path);
        if (!array_key_exists('data', $relationship)) {
            throw $this->malformed($path, 'Database relationship is missing "data".');
        }
        if ($relationship['data'] === null) {
            return null;
        }

        $identifier = $this->valueAsMapping($relationship['data'], $path);
        if ($this->requiredNonEmptyString($identifier, 'type', $path) !== 'databaseSchemas') {
            throw $this->malformed($path, 'Database relationship type must be "databaseSchemas".');
        }

        return $this->requiredNonEmptyString($identifier, 'id', $path);
    }

    /**
     * @param array<string, mixed> $resource
     * @param array<string, mixed> $document
     */
    private function branch(array $resource, array $document, string $path): ?string
    {
        $direct = $this->optionalString($this->mappingAt($resource, 'attributes', $path), 'branch', $path);
        if ($direct !== null) {
            return $direct;
        }

        if (!array_key_exists('included', $document)) {
            return null;
        }

        $relationships = $this->optionalMapping($resource, 'relationships', $path);
        $branch = $relationships === null ? null : $this->optionalMapping($relationships, 'branch', $path);
        $identifier = $branch === null ? null : $this->optionalMapping($branch, 'data', $path);
        $branchId = $identifier === null ? null : $this->optionalString($identifier, 'id', $path);

        if ($branchId === null) {
            return null;
        }

        foreach ($this->listAt($document, 'included', $path) as $included) {
            $included = $this->valueAsMapping($included, $path);
            if ($this->optionalString($included, 'type', $path) !== 'branches'
                || $this->optionalString($included, 'id', $path) !== $branchId) {
                continue;
            }

            return $this->requiredString($this->mappingAt($included, 'attributes', $path), 'name', $path);
        }

        return null;
    }

    /**
     * The generated Laravel Cloud documentation currently displays
     * environment_variables both as an environment attribute and beneath an
     * empty schema grouping key. Supporting both forms keeps that API-specific
     * ambiguity confined to this adapter. Absence remains distinct from [].
     *
     * @param array<string, mixed> $attributes
     */
    private function environmentVariables(
        array $attributes,
        string $path,
    ): ?CloudEnvironmentVariableCollection {
        $container = $attributes;
        if (!array_key_exists('environment_variables', $container)) {
            $documentedGroup = $this->optionalMapping($attributes, '', $path);
            if ($documentedGroup === null || !array_key_exists('environment_variables', $documentedGroup)) {
                return null;
            }
            $container = $documentedGroup;
        }

        $variables = [];
        $seen = [];
        foreach ($this->listAt($container, 'environment_variables', $path) as $value) {
            $variable = $this->valueAsMapping($value, $path);
            $key = $this->requiredString($variable, 'key', $path);
            if (array_key_exists($key, $seen)) {
                throw $this->malformed($path, 'Environment response contains duplicate variable keys.');
            }
            $seen[$key] = true;
            $variables[] = new CloudEnvironmentVariable(
                $key,
                $this->requiredString($variable, 'value', $path),
            );
        }

        return new CloudEnvironmentVariableCollection(...$variables);
    }

    /**
     * @param array<string, mixed> $data
     * @return list<mixed>
     */
    private function listAt(array $data, string $key, string $path): array
    {
        if (!array_key_exists($key, $data) || !is_array($data[$key]) || !array_is_list($data[$key])) {
            throw $this->malformed($path, sprintf('Expected "%s" to be a list.', $key));
        }

        return $data[$key];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function mappingAt(array $data, string $key, string $path): array
    {
        if (!array_key_exists($key, $data)) {
            throw $this->malformed($path, sprintf('Required response field "%s" is missing.', $key));
        }

        return $this->valueAsMapping($data[$key], $path);
    }

    /** @return array<string, mixed> */
    private function valueAsMapping(mixed $value, string $path): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw $this->malformed($path, 'Expected a response mapping.');
        }

        $mapping = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw $this->malformed($path, 'Response mapping keys must be strings.');
            }
            $mapping[$key] = $item;
        }

        return $mapping;
    }

    /** @param array<string, mixed> $data */
    private function requiredString(array $data, string $key, string $path): string
    {
        if (!array_key_exists($key, $data) || !is_string($data[$key])) {
            throw $this->malformed($path, sprintf('Required response field "%s" must be a string.', $key));
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private function requiredNonEmptyString(array $data, string $key, string $path): string
    {
        $value = $this->requiredString($data, $key, $path);
        if (trim($value) === '') {
            throw $this->malformed($path, sprintf('Required response field "%s" must not be empty.', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private function requiredInteger(array $data, string $key, string $path): int
    {
        if (!array_key_exists($key, $data) || !is_int($data[$key])) {
            throw $this->malformed($path, sprintf('Required response field "%s" must be an integer.', $key));
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private function requiredNumber(array $data, string $key, string $path): float
    {
        if (!array_key_exists($key, $data) || (!is_int($data[$key]) && !is_float($data[$key]))) {
            throw $this->malformed($path, sprintf('Required response field "%s" must be a number.', $key));
        }

        return (float) $data[$key];
    }

    /** @param array<string, mixed> $data */
    private function requiredBoolean(array $data, string $key, string $path): bool
    {
        if (!array_key_exists($key, $data) || !is_bool($data[$key])) {
            throw $this->malformed($path, sprintf('Required response field "%s" must be a boolean.', $key));
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private function optionalString(array $data, string $key, string $path): ?string
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }
        if (!is_string($data[$key])) {
            throw $this->malformed($path, sprintf('Optional response field "%s" must be a string or null.', $key));
        }

        return $data[$key];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function optionalMapping(array $data, string $key, string $path): ?array
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        return $this->valueAsMapping($data[$key], $path);
    }

    private function malformed(string $path, string $message): CloudResponseException
    {
        return new CloudResponseException($message, 'GET', $this->safePath($path));
    }

    private function safePath(string $path): string
    {
        if (!str_starts_with($path, 'https://')) {
            return $path;
        }

        $urlPath = parse_url($path, PHP_URL_PATH);
        $query = parse_url($path, PHP_URL_QUERY);

        return (is_string($urlPath) ? $urlPath : '/') . (is_string($query) ? '?' . $query : '');
    }
}
