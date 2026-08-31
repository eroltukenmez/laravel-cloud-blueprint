<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Infrastructure\Http;

use LaravelCloudBlueprint\Cloud\CloudApiToken;
use LaravelCloudBlueprint\Cloud\DTO\CloudLaravelMySqlConfiguration;
use LaravelCloudBlueprint\Cloud\DTO\CloudNeonPostgresConfiguration;
use LaravelCloudBlueprint\Cloud\DTO\CloudUnknownDatabaseConfiguration;
use LaravelCloudBlueprint\Cloud\DTO\CreateDatabaseClusterRequest;
use LaravelCloudBlueprint\Cloud\DTO\CreateDatabaseRequest;
use LaravelCloudBlueprint\Cloud\DTO\CreateLaravelMySqlConfiguration;
use LaravelCloudBlueprint\Cloud\DTO\CreateNeonPostgresConfiguration;
use LaravelCloudBlueprint\Cloud\Exception\CloudApiException;
use LaravelCloudBlueprint\Cloud\Exception\CloudException;
use LaravelCloudBlueprint\Cloud\Exception\CloudResponseException;
use LaravelCloudBlueprint\Cloud\Exception\CloudTransportException;
use LaravelCloudBlueprint\Infrastructure\Http\SymfonyLaravelCloudClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Exception\TransportException;

final class SymfonyLaravelCloudDatabaseClientTest extends TestCase
{
    private const string SECRET = 'DATABASE-SENTINEL-MUST-NEVER-LEAK';

    public function testCreatesLaravelMysqlClusterWithVerifiedPayloadAndDiscardsCredentials(): void
    {
        $resource = self::mysqlResource('created-cluster');
        $response = new MockResponse(self::detail($resource), ['http_code' => 201]);
        $created = $this->client([$response])->createDatabaseCluster(new CreateDatabaseClusterRequest(
            'Primary',
            'laravel_mysql_8',
            'eu-central-1',
            new CreateLaravelMySqlConfiguration('db-flex.m-1vcpu-512mb', 5, 1, false, false),
        ));

        self::assertSame('created-cluster', $created->id);
        self::assertSame('/api/databases/clusters', parse_url($response->getRequestUrl(), PHP_URL_PATH));
        self::assertSame([
            'type' => 'laravel_mysql_8',
            'name' => 'Primary',
            'region' => 'eu-central-1',
            'config' => [
                'size' => 'db-flex.m-1vcpu-512mb',
                'storage' => 5,
                'retention_days' => 1,
                'uses_scheduled_snapshots' => false,
                'is_public' => false,
            ],
        ], self::requestBody($response));
        self::assertStringNotContainsString(self::SECRET, serialize($created));
    }

    public function testCreatesNeonClusterWithVerifiedPayload(): void
    {
        $response = new MockResponse(self::detail(self::neonResource('created-neon')), ['http_code' => 201]);
        $this->client([$response])->createDatabaseCluster(new CreateDatabaseClusterRequest(
            'Primary',
            'neon_serverless_postgres_18',
            'eu-central-1',
            new CreateNeonPostgresConfiguration(0.25, 1.0, 300, 7),
        ));

        $body = self::requestBody($response);
        self::assertSame([
            'cu_min' => 0.25,
            'cu_max' => 1.0,
            'suspend_seconds' => 300,
            'retention_days' => 7,
        ], $body['config']);

        $neon17 = self::withAttribute(self::neonResource('created-neon-17'), 'type', 'neon_serverless_postgres_17');
        $response17 = new MockResponse(self::detail($neon17), ['http_code' => 201]);
        $this->client([$response17])->createDatabaseCluster(new CreateDatabaseClusterRequest(
            'Primary',
            'neon_serverless_postgres_17',
            'eu-central-1',
            new CreateNeonPostgresConfiguration(0.25, 1.0, 300, 7),
        ));
        self::assertSame(
            'neon_serverless_postgres_17',
            self::requestBody($response17)['type'],
        );
    }

    public function testCreatesLogicalDatabaseWithVerifiedEndpointPayloadAndSafeResponse(): void
    {
        $resource = self::databaseResource('created-database', 'application');
        $resource = self::withAttribute($resource, 'connection', ['password' => self::SECRET]);
        $response = new MockResponse(self::detail($resource), ['http_code' => 201]);
        $created = $this->client([$response])->createDatabase('cluster-1', new CreateDatabaseRequest('application'));

        self::assertSame('created-database', $created->id);
        self::assertSame('cluster-1', $created->clusterId);
        self::assertSame('/api/databases/clusters/cluster-1/databases', parse_url($response->getRequestUrl(), PHP_URL_PATH));
        self::assertSame(['name' => 'application'], self::requestBody($response));
        self::assertStringNotContainsString(self::SECRET, serialize($created));
    }

    public function testDatabaseCreateApiErrorsAreSanitizedAndNeverRetried(): void
    {
        foreach ([401, 403, 404, 409, 422, 429, 500] as $status) {
            try {
                $this->client([new MockResponse(
                    '{"message":"' . self::SECRET . '"}',
                    ['http_code' => $status],
                )])->createDatabase('cluster-1', new CreateDatabaseRequest('application'));
                self::fail('Expected Database create failure for HTTP ' . $status);
            } catch (CloudException $exception) {
                self::assertStringNotContainsString(self::SECRET, serialize($exception));
            }
        }

        $attempts = 0;
        $http = new MockHttpClient(static function () use (&$attempts): never {
            ++$attempts;
            throw new TransportException('timeout after send');
        });
        try {
            (new SymfonyLaravelCloudClient($http, new CloudApiToken('secret-token')))
                ->createDatabase('cluster-1', new CreateDatabaseRequest('application'));
            self::fail('Expected uncertain Database create failure.');
        } catch (CloudTransportException $exception) {
            self::assertSame(1, $attempts);
            self::assertStringContainsString('uncertain', $exception->getMessage());
        }
    }

    public function testMalformedDatabaseCreateSuccessIsUncertainAndSecretFree(): void
    {
        try {
            $this->client([new MockResponse(
                '{"data":{"id":"database-1","attributes":{"password":"' . self::SECRET . '"}}}',
                ['http_code' => 201],
            )])->createDatabase('cluster-1', new CreateDatabaseRequest('application'));
            self::fail('Expected malformed Database create response.');
        } catch (CloudResponseException $exception) {
            self::assertStringNotContainsString(self::SECRET, serialize($exception));
        }
    }

    public function testDatabaseCreateEndpointsAcceptExactlyHttp201(): void
    {
        foreach ([200, 202, 204] as $unexpectedStatus) {
            try {
                $this->client([new MockResponse(
                    self::detail(self::mysqlResource('cluster-1')),
                    ['http_code' => $unexpectedStatus],
                )])->createDatabaseCluster(new CreateDatabaseClusterRequest(
                    'Primary',
                    'laravel_mysql_8',
                    'eu-central-1',
                    new CreateLaravelMySqlConfiguration('db-flex.m-1vcpu-512mb', 5, 1, false, false),
                ));
                self::fail('Expected Cluster create status rejection.');
            } catch (CloudResponseException $exception) {
                self::assertSame($unexpectedStatus, $exception->statusCode);
            }

            try {
                $this->client([new MockResponse(
                    self::detail(self::databaseResource('database-1', 'application')),
                    ['http_code' => $unexpectedStatus],
                )])->createDatabase('cluster-1', new CreateDatabaseRequest('application'));
                self::fail('Expected logical Database create status rejection.');
            } catch (CloudResponseException $exception) {
                self::assertSame($unexpectedStatus, $exception->statusCode);
            }
        }
    }

    public function testLaravelMysqlClusterMapsOnlyTypedSafeFields(): void
    {
        $cluster = $this->client([new MockResponse(self::detail(self::mysqlResource('cluster-1')))])
            ->databaseCluster('cluster-1');

        self::assertSame('cluster-1', $cluster->id);
        self::assertSame('Primary', $cluster->name);
        self::assertSame('laravel_mysql_8', $cluster->type);
        self::assertSame('available', $cluster->status);
        self::assertSame('eu-central-1', $cluster->region);
        self::assertInstanceOf(CloudLaravelMySqlConfiguration::class, $cluster->configuration);
        self::assertSame('db-flex.m-1vcpu-512mb', $cluster->configuration->size);
        self::assertSame(5, $cluster->configuration->storage);
        self::assertStringNotContainsString(self::SECRET, serialize($cluster));
    }

    public function testNeonClusterMapsOnlyTypedSafeFields(): void
    {
        $cluster = $this->client([new MockResponse(self::detail(self::neonResource('cluster-2')))])
            ->databaseCluster('cluster-2');

        self::assertInstanceOf(CloudNeonPostgresConfiguration::class, $cluster->configuration);
        self::assertSame(0.25, $cluster->configuration->minimumComputeUnits);
        self::assertSame(1.0, $cluster->configuration->maximumComputeUnits);
        self::assertSame(300, $cluster->configuration->suspendSeconds);
        self::assertSame(7, $cluster->configuration->retentionDays);
        self::assertStringNotContainsString(self::SECRET, serialize($cluster));
    }

    public function testUnknownTypePreservesSafeIdentityAndDiscardsAllConfiguration(): void
    {
        $resource = self::mysqlResource('cluster-unknown');
        $resource = self::withAttribute($resource, 'type', 'future_database_99');
        $resource = self::withAttribute($resource, 'config', ['unexpected' => ['password' => self::SECRET]]);

        $cluster = $this->client([new MockResponse(self::detail($resource))])->databaseCluster('cluster-unknown');

        self::assertSame('future_database_99', $cluster->type);
        self::assertInstanceOf(CloudUnknownDatabaseConfiguration::class, $cluster->configuration);
        self::assertStringNotContainsString(self::SECRET, serialize($cluster));
    }

    public function testClusterListingHandlesEmptyAndNullNext(): void
    {
        $clusters = $this->client([new MockResponse(self::page([]))])->databaseClusters();

        self::assertSame([], $clusters);
    }

    public function testClusterListingFollowsPaginationAndSortsByStableId(): void
    {
        $next = 'https://cloud.laravel.com/api/databases/clusters?page=2';
        $client = $this->client([
            new MockResponse(self::page([self::mysqlResource('z-cluster')], $next)),
            new MockResponse(self::page([self::neonResource('a-cluster')])),
        ]);

        $clusters = $client->databaseClusters();

        self::assertSame(['a-cluster', 'z-cluster'], array_map(static fn ($cluster): string => $cluster->id, $clusters));
        self::assertStringNotContainsString(self::SECRET, serialize($clusters));
    }

    public function testUnknownTypeDoesNotBreakCompleteClusterListing(): void
    {
        $unknown = self::mysqlResource('unknown');
        $unknown = self::withAttribute($unknown, 'type', 'future_type');
        $unknown = self::withAttribute($unknown, 'config', self::SECRET);

        $clusters = $this->client([new MockResponse(self::page([$unknown, self::mysqlResource('known')]))])
            ->databaseClusters();

        self::assertCount(2, $clusters);
        self::assertStringNotContainsString(self::SECRET, serialize($clusters));
    }

    public function testDuplicateClusterIdsAreRejected(): void
    {
        $this->expectException(CloudResponseException::class);
        $this->client([new MockResponse(self::page([
            self::mysqlResource('duplicate'),
            self::mysqlResource('duplicate'),
        ]))])->databaseClusters();
    }

    public function testMalformedClusterAndPaginationFailWithoutLeakingResponseValues(): void
    {
        try {
            $this->client([new MockResponse(self::page([[
                'id' => '',
                'attributes' => ['name' => self::SECRET],
            ]], 123))])->databaseClusters();
            self::fail('Expected malformed Cluster response failure.');
        } catch (CloudResponseException $exception) {
            self::assertStringNotContainsString(self::SECRET, $exception->getMessage());
            self::assertStringNotContainsString(self::SECRET, serialize($exception));
        }
    }

    public function testClusterPaginationRejectsForeignAndRepeatedNextUrls(): void
    {
        foreach ([
            'foreign' => 'https://example.test/api/databases/clusters?page=2',
            'repeated' => '/databases/clusters',
        ] as $case => $next) {
            try {
                $this->client([new MockResponse(self::page([], $next))])->databaseClusters();
                self::fail(sprintf('Expected %s pagination URL failure.', $case));
            } catch (CloudResponseException $exception) {
                self::assertStringNotContainsString(self::SECRET, serialize($exception));
            }
        }
    }

    public function testLogicalDatabaseListPaginatesSortsAndUsesRequestedParentIdentity(): void
    {
        $next = 'https://cloud.laravel.com/api/databases/clusters/cluster-1/databases?page=2';
        $databases = $this->client([
            new MockResponse(self::page([self::databaseResource('database-z', 'reporting')], $next)),
            new MockResponse(self::page([self::databaseResource('database-a', 'application')])),
        ])->databases('cluster-1');

        self::assertSame(['database-a', 'database-z'], array_map(static fn ($database): string => $database->id, $databases));
        self::assertSame('cluster-1', $databases[0]->clusterId);
        self::assertSame('application', $databases[0]->name);
    }

    public function testDuplicateLogicalDatabaseIdsAreRejected(): void
    {
        $this->expectException(CloudResponseException::class);
        $this->client([new MockResponse(self::page([
            self::databaseResource('duplicate', 'application'),
            self::databaseResource('duplicate', 'reporting'),
        ]))])->databases('cluster-1');
    }

    public function testLogicalDatabaseDetailValidatesIdentityAndDiscardsAttributes(): void
    {
        $resource = self::databaseResource('database-1', 'application');
        $resource = self::withAttribute($resource, 'connection', ['password' => self::SECRET]);
        $response = new MockResponse(self::detail($resource));

        $database = $this->client([$response])->database('cluster-1', 'database-1');

        self::assertSame('database-1', $database->id);
        self::assertSame('cluster-1', $database->clusterId);
        self::assertStringNotContainsString(self::SECRET, serialize($database));
        self::assertSame('https://cloud.laravel.com/api/databases/clusters/cluster-1/databases/database-1', $response->getRequestUrl());
    }

    public function testMalformedLogicalDatabaseFailsWithoutRawBody(): void
    {
        try {
            $this->client([new MockResponse(self::page([
                self::malformedDatabaseResource(),
            ]))])->databases('cluster-1');
            self::fail('Expected malformed logical Database response failure.');
        } catch (CloudResponseException $exception) {
            self::assertStringNotContainsString(self::SECRET, serialize($exception));
        }
    }

    public function testEnvironmentDatabaseRelationshipCanBeAbsentNullOrAttached(): void
    {
        $responses = [
            new MockResponse(self::environmentDetail()),
            new MockResponse(self::environmentDetail(['database' => ['data' => null]])),
            new MockResponse(self::environmentDetail(['database' => ['data' => [
                'type' => 'databaseSchemas',
                'id' => 'database-1',
                'attributes' => ['password' => self::SECRET],
            ]]])),
        ];
        $client = $this->client($responses);

        self::assertNull($client->environment('env-1')->databaseId);
        self::assertNull($client->environment('env-1')->databaseId);
        $attached = $client->environment('env-1');
        self::assertSame('database-1', $attached->databaseId);
        self::assertStringNotContainsString(self::SECRET, serialize($attached));
    }

    public function testEnvironmentListRequestsAndParsesDatabaseRelationshipWithoutIncludedPayload(): void
    {
        $response = new MockResponse(self::page([self::environmentResource([
            'database' => ['data' => ['type' => 'databaseSchemas', 'id' => 'database-1']],
        ])]));

        $environment = $this->client([$response])->environments('app-1')[0];

        self::assertSame('database-1', $environment->databaseId);
        self::assertStringContainsString('include=branch,database', $response->getRequestUrl());
    }

    public function testMalformedEnvironmentRelationshipIsRejectedSafely(): void
    {
        try {
            $this->client([new MockResponse(self::environmentDetail([
                'database' => ['data' => [['id' => self::SECRET]]],
            ]))])->environment('env-1');
            self::fail('Expected malformed Database relationship failure.');
        } catch (CloudResponseException $exception) {
            self::assertStringNotContainsString(self::SECRET, serialize($exception));
        }
    }

    public function testDatabaseApiFailureUsesExistingSanitizedException(): void
    {
        try {
            $this->client([new MockResponse('{"connection":{"password":"' . self::SECRET . '"}}', [
                'http_code' => 500,
            ])])->databaseClusters();
            self::fail('Expected Cloud API failure.');
        } catch (CloudApiException $exception) {
            self::assertStringNotContainsString(self::SECRET, serialize($exception));
            self::assertSame('/databases/clusters', $exception->path);
        }
    }

    /** @param list<MockResponse> $responses */
    private function client(array $responses): SymfonyLaravelCloudClient
    {
        return new SymfonyLaravelCloudClient(new MockHttpClient($responses), new CloudApiToken('test-token'));
    }

    /** @return array<string, mixed> */
    private static function requestBody(MockResponse $response): array
    {
        $body = $response->getRequestOptions()['body'] ?? null;
        self::assertIsString($body);
        $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $mapping = [];
        foreach ($decoded as $key => $value) {
            self::assertIsString($key);
            $mapping[$key] = $value;
        }
        return $mapping;
    }

    /** @return array<string, mixed> */
    private static function mysqlResource(string $id): array
    {
        return [
            'id' => $id,
            'type' => 'databaseClusters',
            'attributes' => [
                'name' => 'Primary',
                'type' => 'laravel_mysql_8',
                'status' => 'available',
                'region' => 'eu-central-1',
                'config' => [
                    'size' => 'db-flex.m-1vcpu-512mb',
                    'storage' => 5,
                    'retention_days' => 1,
                    'uses_scheduled_snapshots' => false,
                    'is_public' => false,
                ],
                'connection' => self::connectionSecrets(),
                'username' => self::SECRET,
                'password' => self::SECRET,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function neonResource(string $id): array
    {
        $resource = self::mysqlResource($id);
        $resource = self::withAttribute($resource, 'type', 'neon_serverless_postgres_18');
        $resource = self::withAttribute($resource, 'config', [
            'cu_min' => 0.25,
            'cu_max' => 1,
            'suspend_seconds' => 300,
            'retention_days' => 7,
        ]);
        return $resource;
    }

    /** @return array<string, mixed> */
    private static function malformedDatabaseResource(): array
    {
        return [
            'id' => 'database-1',
            'attributes' => ['name' => 42, 'password' => self::SECRET],
        ];
    }

    /** @return array<string, mixed> */
    private static function databaseResource(string $id, string $name): array
    {
        return ['id' => $id, 'type' => 'databaseSchemas', 'attributes' => ['name' => $name]];
    }

    /**
     * @param array<string, mixed> $relationships
     * @return array<string, mixed>
     */
    private static function environmentResource(array $relationships = []): array
    {
        return [
            'id' => 'env-1',
            'type' => 'environments',
            'attributes' => ['name' => 'production', 'environment_variables' => []],
            'relationships' => $relationships,
        ];
    }

    /** @param array<string, mixed> $relationships */
    private static function environmentDetail(array $relationships = []): string
    {
        return self::detail(self::environmentResource($relationships));
    }

    /** @param array<string, mixed> $resource */
    private static function detail(array $resource): string
    {
        return json_encode(['data' => $resource], JSON_THROW_ON_ERROR);
    }

    /** @param list<array<string, mixed>> $resources */
    private static function page(array $resources, mixed $next = null): string
    {
        return json_encode(['data' => $resources, 'links' => ['next' => $next]], JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $resource
     * @return array<string, mixed>
     */
    private static function withAttribute(array $resource, string $key, mixed $value): array
    {
        $attributes = $resource['attributes'] ?? null;
        if (!is_array($attributes)) {
            throw new \InvalidArgumentException('Test resource attributes must be an array.');
        }

        $attributes[$key] = $value;
        $resource['attributes'] = $attributes;
        return $resource;
    }

    /** @return array<string, mixed> */
    private static function connectionSecrets(): array
    {
        return [
            'username' => self::SECRET,
            'password' => self::SECRET,
            'hostname' => self::SECRET,
            'port' => self::SECRET,
            'dsn' => self::SECRET,
            'url' => self::SECRET,
            'nested' => ['tls' => self::SECRET],
        ];
    }
}
