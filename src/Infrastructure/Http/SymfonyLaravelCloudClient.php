<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Infrastructure\Http;

use LaravelCloudBlueprint\Cloud\CloudApiToken;
use LaravelCloudBlueprint\Blueprint\SourceProvider;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudDatabaseMutationClient;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudDatabaseClusterDeletionClient;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudLogicalDatabaseDeletionClient;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudDatabaseLifecycleClient;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudEnvironmentMutationClient;
use LaravelCloudBlueprint\Cloud\DTO\CloudApplication;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabase;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabaseCluster;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabaseClusterConfiguration;
use LaravelCloudBlueprint\Cloud\DTO\CloudDatabaseSnapshot;
use LaravelCloudBlueprint\Cloud\DTO\DatabaseSnapshotStatus;
use LaravelCloudBlueprint\Cloud\DTO\DatabaseSnapshotType;
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
use LaravelCloudBlueprint\Cloud\DTO\CreatedCloudDatabaseCluster;
use LaravelCloudBlueprint\Cloud\DTO\CreateDatabaseRequest;
use LaravelCloudBlueprint\Cloud\DTO\EnvironmentVariableInput;
use LaravelCloudBlueprint\Cloud\DTO\EnvironmentDependencies;
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

final readonly class SymfonyLaravelCloudClient implements LaravelCloudDatabaseMutationClient, LaravelCloudDatabaseLifecycleClient, LaravelCloudEnvironmentMutationClient, LaravelCloudLogicalDatabaseDeletionClient, LaravelCloudDatabaseClusterDeletionClient
{
    private const string ENVIRONMENT_DEPENDENCY_INCLUDES = 'application,branch,deployments,currentDeployment,primaryDomain,instances,database,cache,buckets,websocketApplication,secrets';
    private const string DATABASE_DESTRUCTIVE_INCLUDES = 'database,environments';
    private const string BASE_URL = 'https://cloud.laravel.com/api';
    private const string USER_AGENT = 'Laravel-Cloud-Blueprint/0.1.0-alpha.7';

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
            $this->requiredResourceId($resource, 'id', $path),
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
                    $this->requiredResourceId($resource, 'id', $path),
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
        $initialPath = sprintf(
            '/applications/%s/environments?include=%s',
            rawurlencode($applicationId),
            self::ENVIRONMENT_DEPENDENCY_INCLUDES,
        );

        foreach ($this->pages($initialPath) as [$document, $path]) {
            foreach ($this->listAt($document, 'data', $path) as $resource) {
                $resource = $this->valueAsMapping($resource, $path);
                $attributes = $this->mappingAt($resource, 'attributes', $path);

                $dependencies = $this->environmentDependencies($resource, $document, $path, $applicationId);
                $environments[] = new CloudEnvironment(
                    $this->requiredResourceId($resource, 'id', $path),
                    $applicationId,
                    $this->requiredString($attributes, 'name', $path),
                    $this->branch($resource, $document, $path),
                    $dependencies->databaseId,
                    $dependencies,
                );
            }
        }

        return $environments;
    }

    public function environment(string $environmentId): CloudEnvironmentDetails
    {
        $path = sprintf(
            '/environments/%s?include=%s',
            rawurlencode($environmentId),
            self::ENVIRONMENT_DEPENDENCY_INCLUDES,
        );
        $document = $this->get($path);
        $resource = $this->mappingAt($document, 'data', $path);
        $id = $this->requiredResourceId($resource, 'id', $path);
        if ($id !== $environmentId) {
            throw $this->malformed($path, 'Environment response identity does not match the requested environment.');
        }
        $attributes = $this->mappingAt($resource, 'attributes', $path);

        $dependencies = $this->environmentDependencies($resource, $document, $path);

        return new CloudEnvironmentDetails(
            $id,
            $this->requiredString($attributes, 'name', $path),
            $this->environmentVariables($attributes, $path),
            $dependencies->databaseId,
            $dependencies,
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
        $path = sprintf('/databases/clusters/%s?include=databases', rawurlencode($clusterId));
        $resource = $this->mappingAt($this->get($path), 'data', $path);
        $cluster = $this->databaseClusterFromResource($resource, $path);
        if ($cluster->id !== $clusterId) {
            throw $this->malformed($path, 'Database Cluster response identity does not match the requested Cluster.');
        }

        return $cluster;
    }

    public function databaseSnapshots(string $clusterId): array
    {
        $snapshots = [];
        $seen = [];
        $path = sprintf('/databases/clusters/%s/snapshots', rawurlencode($clusterId));
        $visited = [];
        $expectedPage = 1;

        while (true) {
            if (isset($visited[$path]) || count($visited) >= 1000) {
                throw $this->malformed($path, 'Snapshot pagination is cyclic or exceeds the safety limit.');
            }
            $visited[$path] = true;
            $document = $this->get($path);
            $links = $this->mappingAt($document, 'links', $path);
            $meta = $this->mappingAt($document, 'meta', $path);
            $currentPage = $this->requiredInteger($meta, 'current_page', $path);
            $lastPage = $this->requiredInteger($meta, 'last_page', $path);
            if ($currentPage !== $expectedPage || $lastPage < $currentPage) {
                throw $this->malformed($path, 'Snapshot pagination metadata is inconsistent.');
            }

            foreach ($this->listAt($document, 'data', $path) as $value) {
                $snapshot = $this->databaseSnapshotFromResource($this->valueAsMapping($value, $path), $clusterId, $path);
                if (isset($seen[$snapshot->id])) {
                    throw $this->malformed($path, 'Snapshot response contains duplicate IDs.');
                }
                $seen[$snapshot->id] = true;
                $snapshots[] = $snapshot;
            }

            $next = $this->optionalString($links, 'next', $path);
            if ($currentPage === $lastPage) {
                if ($next !== null) {
                    throw $this->malformed($path, 'Snapshot pagination continues after its final page.');
                }
                break;
            }
            if ($next === null) {
                throw $this->malformed($path, 'Snapshot pagination ended before its final page.');
            }
            $path = $next;
            ++$expectedPage;
        }

        usort($snapshots, static fn (CloudDatabaseSnapshot $left, CloudDatabaseSnapshot $right): int => $left->id <=> $right->id);
        return $snapshots;
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
        return $this->databaseDetail($clusterId, $databaseId, false);
    }

    public function databaseWithDestructiveRelationships(string $clusterId, string $databaseId): CloudDatabase
    {
        return $this->databaseDetail($clusterId, $databaseId, true);
    }

    private function databaseDetail(string $clusterId, string $databaseId, bool $destructiveRelationships): CloudDatabase
    {
        $path = sprintf(
            '/databases/clusters/%s/databases/%s%s',
            rawurlencode($clusterId),
            rawurlencode($databaseId),
            $destructiveRelationships ? '?include=' . self::DATABASE_DESTRUCTIVE_INCLUDES : '',
        );
        $resource = $this->mappingAt($this->get($path), 'data', $path);
        $database = $this->databaseFromResource($resource, $clusterId, $path);
        if ($database->id !== $databaseId) {
            throw $this->malformed($path, 'Logical Database response identity does not match the requested Database.');
        }

        return $database;
    }

    public function createDatabaseCluster(CreateDatabaseClusterRequest $request): CreatedCloudDatabaseCluster
    {
        $path = '/databases/clusters';
        $document = $this->post($path, [
            'type' => $request->type,
            'name' => $request->name,
            'region' => $request->region,
            'config' => $request->configuration->payload(),
        ]);

        $resource = $this->mappingAt($document, 'data', $path);
        $cluster = $this->databaseClusterFromResource($resource, $path);
        if (!$cluster->childDiscoveryComplete || count($cluster->databaseIds) !== 1) {
            throw $this->malformed(
                $path,
                'Database Cluster create response must contain exactly one valid default Database relationship.',
            );
        }

        $defaultId = $cluster->databaseIds[0];
        $defaultName = $this->createdDefaultDatabaseName($document, $cluster->id, $defaultId, $path);

        return new CreatedCloudDatabaseCluster($cluster, $defaultId, $defaultName);
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
            $this->requiredResourceId($resource, 'id', $path),
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
            $this->requiredResourceId($resource, 'id', $path),
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
        $id = $this->requiredResourceId($resource, 'id', $path);
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

    public function deleteEnvironment(string $environmentId): void
    {
        $path = sprintf('/environments/%s', rawurlencode($environmentId));
        $this->delete($path);
    }

    public function deleteDatabase(string $clusterId, string $databaseId): void
    {
        $path = sprintf(
            '/databases/clusters/%s/databases/%s',
            rawurlencode($clusterId),
            rawurlencode($databaseId),
        );
        $this->delete($path);
    }

    public function deleteDatabaseCluster(string $clusterId): void
    {
        $this->delete(sprintf('/databases/clusters/%s', rawurlencode($clusterId)));
    }

    /**
     * @return iterable<array{array<string, mixed>, string}>
     */
    private function pages(string $initialPath): iterable
    {
        $path = $initialPath;
        $visited = [];

        while (true) {
            if (isset($visited[$path])) {
                throw $this->malformed($path, 'Pagination response contains a repeated next URL.');
            }
            $visited[$path] = true;

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

    private function delete(string $path): void
    {
        try {
            $response = $this->http->request('DELETE', self::BASE_URL . $path, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token->value(),
                    'Accept' => 'application/json',
                    'User-Agent' => self::USER_AGENT,
                ],
            ]);
            $status = $response->getStatusCode();
            $requestId = $this->requestId($response);
        } catch (TransportExceptionInterface) {
            throw new CloudTransportException(
                'Laravel Cloud delete request failed with an uncertain remote outcome. Run plan before retrying.',
                'DELETE',
                $path,
            );
        }

        $this->guardStatus($status, $path, $requestId, 'DELETE', $response);

        if ($status !== 204) {
            throw new CloudResponseException(
                'Laravel Cloud delete response did not return HTTP 204.',
                'DELETE',
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
        [$databaseIds, $complete, $missing, $unknown] = $this->databaseRelationshipIds(
            $resource,
            'databases',
            'databaseSchemas',
            true,
            $path,
        );
        $unknown = array_values(array_unique([...$unknown, ...$this->unknownDatabaseRelationships($resource, ['databases'])]));
        if ($unknown !== []) {
            $complete = false;
        }

        return new CloudDatabaseCluster(
            $this->requiredResourceId($resource, 'id', $path),
            $this->requiredNonEmptyString($attributes, 'name', $path),
            $type,
            $this->requiredNonEmptyString($attributes, 'status', $path),
            $this->requiredNonEmptyString($attributes, 'region', $path),
            $this->databaseConfiguration($type, $attributes, $path),
            $databaseIds,
            $complete,
            $missing,
            $unknown,
        );
    }

    /** @param array<string, mixed> $document */
    private function createdDefaultDatabaseName(
        array $document,
        string $clusterId,
        string $defaultDatabaseId,
        string $path,
    ): ?string {
        if (!array_key_exists('included', $document)) {
            return null;
        }

        $matching = [];
        foreach ($this->listAt($document, 'included', $path) as $value) {
            $included = $this->valueAsMapping($value, $path);
            $type = $this->optionalString($included, 'type', $path);
            $id = $this->optionalResourceId($included, 'id', $path);
            if ($id === $defaultDatabaseId && $type !== 'databaseSchemas') {
                throw $this->malformed($path, 'Default Database included identity has an unexpected resource type.');
            }
            if ($type !== 'databaseSchemas') {
                continue;
            }
            if ($id !== $defaultDatabaseId) {
                throw $this->malformed($path, 'Cluster create response includes an unlinked logical Database.');
            }
            $matching[] = $included;
        }

        if (count($matching) > 1) {
            throw $this->malformed($path, 'Cluster create response contains duplicate included default Databases.');
        }
        if ($matching === []) {
            return null;
        }

        $included = $matching[0];
        if (array_key_exists('relationships', $included)) {
            if (!is_array($included['relationships'])) {
                throw $this->malformed($path, 'Included default Database relationships are malformed.');
            }
            if (array_key_exists('database', $included['relationships'])) {
                [$parentIds, $complete] = $this->databaseRelationshipIds(
                    $included,
                    'database',
                    'databaseClusters',
                    false,
                    $path,
                );
                if (!$complete || $parentIds !== [$clusterId]) {
                    throw $this->malformed(
                        $path,
                        'Included default Database belongs to an unexpected Database Cluster.',
                    );
                }
            }
        }

        $attributes = $this->optionalMapping($included, 'attributes', $path);
        if ($attributes === null || !array_key_exists('name', $attributes)) {
            return null;
        }

        return $this->requiredNonEmptyString($attributes, 'name', $path);
    }

    /** @param array<string, mixed> $resource */
    private function databaseSnapshotFromResource(array $resource, string $clusterId, string $path): CloudDatabaseSnapshot
    {
        $attributes = $this->mappingAt($resource, 'attributes', $path);
        $relationships = $this->mappingAt($resource, 'relationships', $path);
        $database = $this->mappingAt($relationships, 'database', $path);
        $parent = $this->mappingAt($database, 'data', $path);
        if ($this->requiredNonEmptyString($parent, 'type', $path) !== 'databases'
            || $this->requiredResourceId($parent, 'id', $path) !== $clusterId) {
            throw $this->malformed($path, 'Snapshot parent identity does not match the requested Cluster.');
        }

        $type = DatabaseSnapshotType::tryFrom($this->requiredNonEmptyString($attributes, 'type', $path));
        if ($type === null) {
            throw $this->malformed($path, 'Snapshot type is unknown.');
        }

        return new CloudDatabaseSnapshot(
            $this->requiredResourceId($resource, 'id', $path),
            $clusterId,
            $type,
            DatabaseSnapshotStatus::tryFrom($this->requiredNonEmptyString($attributes, 'status', $path)),
            $this->requiredBoolean($attributes, 'pitr_enabled', $path),
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
        [$parentIds, $parentComplete, $parentMissing, $parentUnknown] = $this->databaseRelationshipIds(
            $resource,
            'database',
            'databases',
            false,
            $path,
        );
        [$environmentIds, $environmentComplete, $environmentMissing, $environmentUnknown] =
            $this->databaseRelationshipIds($resource, 'environments', 'environments', true, $path);
        $unexpected = $this->unknownDatabaseRelationships($resource, ['database', 'environments']);

        return new CloudDatabase(
            $this->requiredResourceId($resource, 'id', $path),
            $clusterId,
            $this->requiredNonEmptyString($this->mappingAt($resource, 'attributes', $path), 'name', $path),
            count($parentIds) === 1 ? $parentIds[0] : null,
            $environmentIds,
            $parentComplete && $environmentComplete && $unexpected === [],
            array_values(array_unique([...$parentMissing, ...$environmentMissing])),
            array_values(array_unique([...$parentUnknown, ...$environmentUnknown, ...$unexpected])),
        );
    }

    /**
     * Tolerantly reads destructive relationship linkage. Missing or malformed evidence is retained as
     * incomplete rather than normalized to an authoritative empty collection.
     *
     * @param array<string, mixed> $resource
     * @return array{list<string>, bool, list<string>, list<string>}
     */
    private function databaseRelationshipIds(
        array $resource,
        string $name,
        string $expectedType,
        bool $many,
        string $path,
    ): array {
        if (!array_key_exists('relationships', $resource)) {
            return [[], false, [$name], []];
        }
        if (!is_array($resource['relationships'])) {
            return [[], false, [], [$name]];
        }
        $relationships = $resource['relationships'];
        if (!array_key_exists($name, $relationships)) {
            return [[], false, [$name], []];
        }
        $relationship = $relationships[$name];
        if (!is_array($relationship) || !array_key_exists('data', $relationship)) {
            return [[], false, [], [$name]];
        }
        $data = $relationship['data'];
        $items = $many ? $data : [$data];
        if (!is_array($items) || (!$many && $data === null)) {
            return [[], false, [], [$name]];
        }

        $ids = [];
        foreach ($items as $item) {
            try {
                $item = $this->valueAsMapping($item, $path);
                if (($item['type'] ?? null) !== $expectedType) {
                    return [[], false, [], [$name]];
                }
                $id = $this->requiredResourceId($item, 'id', $path);
            } catch (CloudResponseException) {
                return [[], false, [], [$name]];
            }
            $identityKey = 'id:' . $id;
            if (isset($ids[$identityKey])) {
                return [[], false, [], [$name]];
            }
            $ids[$identityKey] = $id;
        }

        $values = array_values($ids);
        sort($values, SORT_STRING);
        return [$values, true, [], []];
    }

    /**
     * @param array<string, mixed> $resource
     * @param list<string> $known
     * @return list<string>
     */
    private function unknownDatabaseRelationships(array $resource, array $known): array
    {
        if (!isset($resource['relationships']) || !is_array($resource['relationships'])) {
            return [];
        }
        $unknown = array_values(array_diff(array_keys($resource['relationships']), $known));
        sort($unknown, SORT_STRING);
        return $unknown;
    }

    /**
     * @param array<string, mixed> $resource
     * @param array<string, mixed> $document
     */
    private function environmentDependencies(
        array $resource,
        array $document,
        string $path,
        ?string $expectedApplicationId = null,
    ): EnvironmentDependencies {
        $relationships = $this->optionalMapping($resource, 'relationships', $path);
        if ($relationships === null) {
            return EnvironmentDependencies::incomplete();
        }

        $complete = true;
        $known = [
            'application', 'branch', 'deployments', 'currentDeployment', 'domains', 'primaryDomain',
            'instances', 'database', 'cache', 'buckets', 'websocketApplication', 'secrets',
        ];
        $missing = array_values(array_diff($known, array_keys($relationships)));
        $applicationId = $this->dependencyId($relationships, 'application', 'applications', $path, $complete);
        if ($expectedApplicationId !== null && $applicationId !== null && $applicationId !== $expectedApplicationId) {
            throw $this->malformed($path, 'Environment relationship belongs to an unexpected Application.');
        }

        $databaseId = $this->dependencyId($relationships, 'database', 'databaseSchemas', $path, $complete);
        $cacheId = $this->dependencyId($relationships, 'cache', 'caches', $path, $complete);
        $websocketId = $this->dependencyId(
            $relationships,
            'websocketApplication',
            'websocketApplications',
            $path,
            $complete,
        );
        $environmentId = $this->requiredResourceId($resource, 'id', $path);
        if (array_key_exists('domains', $relationships)) {
            $domainCount = $this->dependencyCount($relationships, 'domains', 'domains', $path, $complete);
        } elseif (array_values(array_diff($missing, ['domains'])) === []) {
            $domainCount = $this->environmentDomainCount($environmentId);
            $missing = array_values(array_diff($missing, ['domains']));
            $complete = true;
        } else {
            $domainCount = 0;
        }
        $instanceCount = $this->dependencyCount($relationships, 'instances', 'instances', $path, $complete);
        $deploymentCount = $this->dependencyCount($relationships, 'deployments', 'deployments', $path, $complete);
        $secretCount = $this->dependencyCount($relationships, 'secrets', 'secrets', $path, $complete);
        $filesystemCount = $this->dependencyCount($relationships, 'buckets', 'filesystems', $path, $complete);
        $currentDeployment = $this->dependencyId(
            $relationships,
            'currentDeployment',
            'deployments',
            $path,
            $complete,
        ) !== null;
        $this->dependencyId($relationships, 'primaryDomain', 'domains', $path, $complete);
        $this->dependencyId($relationships, 'branch', 'branches', $path, $complete);

        $unknown = array_values(array_diff(array_keys($relationships), $known));
        sort($unknown, SORT_STRING);
        if ($unknown !== []) {
            $complete = false;
        }

        $isDefault = $this->defaultEnvironmentStatus(
            $document,
            $applicationId,
            $resource,
            $path,
            $complete,
            $missing,
        );

        return new EnvironmentDependencies(
            $databaseId,
            $cacheId,
            $websocketId,
            $domainCount,
            $instanceCount,
            $deploymentCount,
            $secretCount,
            $filesystemCount,
            $currentDeployment,
            $isDefault,
            $complete,
            $unknown,
            $missing,
        );
    }

    /**
     * @param array<string, mixed> $relationships
     */
    private function dependencyId(
        array $relationships,
        string $name,
        string $type,
        string $path,
        bool &$complete,
    ): ?string {
        if (!array_key_exists($name, $relationships)) {
            $complete = false;
            return null;
        }
        $relationship = $this->valueAsMapping($relationships[$name], $path);
        if (!array_key_exists('data', $relationship)) {
            throw $this->malformed($path, sprintf('Environment relationship "%s" is missing "data".', $name));
        }
        if ($relationship['data'] === null) {
            return null;
        }
        $identifier = $this->valueAsMapping($relationship['data'], $path);
        if ($this->requiredNonEmptyString($identifier, 'type', $path) !== $type) {
            throw $this->malformed($path, sprintf('Environment relationship "%s" has an unexpected type.', $name));
        }
        return $this->requiredResourceId($identifier, 'id', $path);
    }

    /** @param array<string, mixed> $relationships */
    private function dependencyCount(
        array $relationships,
        string $name,
        string $type,
        string $path,
        bool &$complete,
    ): int {
        if (!array_key_exists($name, $relationships)) {
            $complete = false;
            return 0;
        }
        $relationship = $this->valueAsMapping($relationships[$name], $path);
        if (!array_key_exists('data', $relationship)) {
            throw $this->malformed($path, sprintf('Environment relationship "%s" is missing "data".', $name));
        }
        $identifiers = $this->listAt($relationship, 'data', $path);
        foreach ($identifiers as $value) {
            $identifier = $this->valueAsMapping($value, $path);
            if ($this->requiredNonEmptyString($identifier, 'type', $path) !== $type) {
                throw $this->malformed($path, sprintf('Environment relationship "%s" has an unexpected type.', $name));
            }
            $this->requiredResourceId($identifier, 'id', $path);
        }
        return count($identifiers);
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $environment
     * @param list<string> $missing
     */
    private function defaultEnvironmentStatus(
        array $document,
        ?string $applicationId,
        array $environment,
        string $path,
        bool &$complete,
        array &$missing,
    ): ?bool {
        if ($applicationId === null) {
            $complete = false;
            $missing[] = 'included.application';
            return null;
        }
        foreach (array_key_exists('included', $document) ? $this->listAt($document, 'included', $path) : [] as $value) {
            $included = $this->valueAsMapping($value, $path);
            if ($this->optionalString($included, 'type', $path) !== 'applications'
                || $this->optionalResourceId($included, 'id', $path) !== $applicationId) {
                continue;
            }
            $relationships = $this->optionalMapping($included, 'relationships', $path);
            if ($relationships === null) {
                break;
            }
            if (array_key_exists('defaultEnvironment', $relationships)) {
                $defaultId = $this->dependencyId(
                    $relationships,
                    'defaultEnvironment',
                    'environments',
                    $path,
                    $complete,
                );
                return $defaultId !== null && $defaultId === $this->requiredResourceId($environment, 'id', $path);
            }
            break;
        }

        if (!$complete) {
            $missing[] = 'application.defaultEnvironment';
            return null;
        }

        [$found, $defaultId] = $this->applicationDefaultEnvironmentId($applicationId);
        if (!$found) {
            $complete = false;
            $missing[] = 'application.defaultEnvironment';
            return null;
        }
        $missing = array_values(array_diff($missing, ['application.defaultEnvironment']));

        return $defaultId !== null && $defaultId === $this->requiredResourceId($environment, 'id', $path);
    }

    private function environmentDomainCount(string $environmentId): int
    {
        $count = 0;
        $initialPath = sprintf('/environments/%s/domains', rawurlencode($environmentId));
        foreach ($this->pages($initialPath) as [$document, $path]) {
            foreach ($this->listAt($document, 'data', $path) as $value) {
                $domain = $this->valueAsMapping($value, $path);
                if ($this->requiredNonEmptyString($domain, 'type', $path) !== 'domains') {
                    throw $this->malformed($path, 'Domain response has an unexpected resource type.');
                }
                $this->requiredResourceId($domain, 'id', $path);
                ++$count;
            }
        }

        return $count;
    }

    /** @return array{bool, ?string} */
    private function applicationDefaultEnvironmentId(string $applicationId): array
    {
        $path = sprintf('/applications/%s?include=defaultEnvironment', rawurlencode($applicationId));
        $document = $this->get($path);
        $application = $this->mappingAt($document, 'data', $path);
        if ($this->requiredNonEmptyString($application, 'type', $path) !== 'applications'
            || $this->requiredResourceId($application, 'id', $path) !== $applicationId) {
            throw $this->malformed($path, 'Application response identity does not match the Environment parent.');
        }
        $relationships = $this->mappingAt($application, 'relationships', $path);
        if (!array_key_exists('defaultEnvironment', $relationships)) {
            return [false, null];
        }
        $complete = true;

        return [true, $this->dependencyId(
            $relationships,
            'defaultEnvironment',
            'environments',
            $path,
            $complete,
        )];
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
        $branchId = $identifier === null ? null : $this->optionalResourceId($identifier, 'id', $path);

        if ($branchId === null) {
            return null;
        }

        foreach ($this->listAt($document, 'included', $path) as $included) {
            $included = $this->valueAsMapping($included, $path);
            if ($this->optionalString($included, 'type', $path) !== 'branches'
                || $this->optionalResourceId($included, 'id', $path) !== $branchId) {
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
    private function requiredResourceId(array $data, string $key, string $path): string
    {
        if (!array_key_exists($key, $data)) {
            throw $this->malformed($path, sprintf('Required resource identifier "%s" is missing.', $key));
        }

        $value = $data[$key];
        if (is_int($value)) {
            return (string) $value;
        }
        if (!is_string($value) || trim($value) === '') {
            throw $this->malformed(
                $path,
                sprintf('Required resource identifier "%s" must be a non-empty string or integer.', $key),
            );
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private function optionalResourceId(array $data, string $key, string $path): ?string
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        return $this->requiredResourceId($data, $key, $path);
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
