<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Infrastructure\Http;

use LaravelCloudBlueprint\Cloud\CloudApiToken;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudClient;
use LaravelCloudBlueprint\Cloud\DTO\CloudApplication;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironment;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironmentDetails;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironmentVariable;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironmentVariableCollection;
use LaravelCloudBlueprint\Cloud\DTO\CloudOrganization;
use LaravelCloudBlueprint\Cloud\DTO\CreateApplicationRequest;
use LaravelCloudBlueprint\Cloud\DTO\CreateEnvironmentRequest;
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

final readonly class SymfonyLaravelCloudClient implements LaravelCloudClient
{
    private const string BASE_URL = 'https://cloud.laravel.com/api';
    private const string USER_AGENT = 'Laravel-Cloud-Blueprint/0.1.0-alpha.1';

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
                );
            }
        }

        return $applications;
    }

    public function environments(string $applicationId): array
    {
        $environments = [];
        $initialPath = sprintf('/applications/%s/environments?include=branch', rawurlencode($applicationId));

        foreach ($this->pages($initialPath) as [$document, $path]) {
            foreach ($this->listAt($document, 'data', $path) as $resource) {
                $resource = $this->valueAsMapping($resource, $path);
                $attributes = $this->mappingAt($resource, 'attributes', $path);

                $environments[] = new CloudEnvironment(
                    $this->requiredString($resource, 'id', $path),
                    $applicationId,
                    $this->requiredString($attributes, 'name', $path),
                    $this->branch($resource, $document, $path),
                );
            }
        }

        return $environments;
    }

    public function environment(string $environmentId): CloudEnvironmentDetails
    {
        $path = sprintf('/environments/%s', rawurlencode($environmentId));
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
        );
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
        );
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

        $this->guardStatus($status, $path, $requestId);

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
     * @param array<string, string> $payload
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

        $this->guardStatus($status, $path, $requestId, 'POST');

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

    private function guardStatus(int $status, string $path, ?string $requestId, string $method = 'GET'): void
    {
        if ($status >= 200 && $status < 300) {
            return;
        }

        $context = [$method, $this->safePath($path), $status, $requestId];

        throw match (true) {
            $status === 401 || $status === 403 => new CloudAuthenticationException('Laravel Cloud authentication or authorization failed.', ...$context),
            $status === 404 => new CloudResourceNotFoundException('The requested Laravel Cloud resource was not found.', ...$context),
            $status === 429 => new CloudRateLimitException('Laravel Cloud API rate limit exceeded.', ...$context),
            $status === 422 => new CloudValidationException('Laravel Cloud rejected the create request.', ...$context),
            $status >= 500 => new CloudApiException('Laravel Cloud API is unavailable.', ...$context),
            default => new CloudApiException('Laravel Cloud API request failed.', ...$context),
        };
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
