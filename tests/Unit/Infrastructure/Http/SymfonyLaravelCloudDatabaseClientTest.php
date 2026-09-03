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
use LaravelCloudBlueprint\Cloud\Exception\CloudAuthenticationException;
use LaravelCloudBlueprint\Cloud\Exception\CloudException;
use LaravelCloudBlueprint\Cloud\Exception\CloudResourceNotFoundException;
use LaravelCloudBlueprint\Cloud\Exception\CloudResponseException;
use LaravelCloudBlueprint\Cloud\Exception\CloudTransportException;
use LaravelCloudBlueprint\Cloud\Exception\CloudValidationException;
use LaravelCloudBlueprint\Infrastructure\Http\SymfonyLaravelCloudClient;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Exception\TransportException;

final class SymfonyLaravelCloudDatabaseClientTest extends TestCase
{
    private const string SECRET = 'DATABASE-SENTINEL-MUST-NEVER-LEAK';

    public function testCreatesLaravelMysqlClusterWithVerifiedPayloadAndDiscardsCredentials(): void
    {
        $resource = self::mysqlResource('created-cluster');
        $response = new MockResponse(self::clusterCreateDetail($resource), ['http_code' => 201]);
        $created = $this->client([$response])->createDatabaseCluster(new CreateDatabaseClusterRequest(
            'Primary',
            'laravel_mysql_8',
            'eu-central-1',
            new CreateLaravelMySqlConfiguration('db-flex.m-1vcpu-512mb', 5, 1, false, false),
        ));

        self::assertSame('created-cluster', $created->cluster->id);
        self::assertSame('default-database', $created->defaultDatabaseId);
        self::assertSame('safe-informational-name', $created->defaultDatabaseName);
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
        $response = new MockResponse(self::clusterCreateDetail(self::neonResource('created-neon')), ['http_code' => 201]);
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
        $response17 = new MockResponse(self::clusterCreateDetail($neon17), ['http_code' => 201]);
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

    public function testClusterCreateCanonicalizesNumericResourceRelationshipIncludedAndParentIds(): void
    {
        $created = $this->client([new MockResponse(self::clusterCreateDetail(
            self::mysqlResource(100),
            relationshipId: 200,
            includedId: 200,
            includedParentId: 100,
        ), ['http_code' => 201])])->createDatabaseCluster(new CreateDatabaseClusterRequest(
            'Primary',
            'laravel_mysql_8',
            'eu-central-1',
            new CreateLaravelMySqlConfiguration('db-flex.m-1vcpu-512mb', 5, 1, false, false),
        ));

        self::assertSame('100', $created->cluster->id);
        self::assertSame('200', $created->defaultDatabaseId);
    }

    public function testClusterCreateCorrelatesMixedNumericAndStringIdsCanonically(): void
    {
        $created = $this->client([new MockResponse(self::clusterCreateDetail(
            self::mysqlResource('100'),
            relationshipId: 200,
            includedId: '200',
            includedParentId: 100,
        ), ['http_code' => 201])])->createDatabaseCluster(new CreateDatabaseClusterRequest(
            'Primary',
            'laravel_mysql_8',
            'eu-central-1',
            new CreateLaravelMySqlConfiguration('db-flex.m-1vcpu-512mb', 5, 1, false, false),
        ));

        self::assertSame('100', $created->cluster->id);
        self::assertSame('200', $created->defaultDatabaseId);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidCreateIdentifierBoundaries(): iterable
    {
        yield 'top-level Cluster ID' => ['cluster', []];
        yield 'included child ID' => ['included', 1.5];
        yield 'included parent ID' => ['parent', false];
    }

    #[DataProvider('invalidCreateIdentifierBoundaries')]
    public function testClusterCreateIdentifierBoundaryErrorsAreSanitized(string $boundary, mixed $id): void
    {
        $resource = self::mysqlResource('cluster-1');
        if ($boundary === 'cluster') {
            $resource['id'] = $id;
        }
        $document = self::clusterCreateDetail(
            $resource,
            includedId: $boundary === 'included' ? $id : 'default-database',
            includedParentId: $boundary === 'parent' ? $id : null,
        );

        try {
            $this->client([new MockResponse($document, ['http_code' => 201])])
                ->createDatabaseCluster(new CreateDatabaseClusterRequest(
                    'Primary',
                    'laravel_mysql_8',
                    'eu-central-1',
                    new CreateLaravelMySqlConfiguration('db-flex.m-1vcpu-512mb', 5, 1, false, false),
                ));
            self::fail('Expected malformed identifier rejection.');
        } catch (CloudResponseException $exception) {
            self::assertStringNotContainsString(self::SECRET, serialize($exception));
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidResourceIdentifiers(): iterable
    {
        yield 'null' => [null];
        yield 'empty string' => [''];
        yield 'whitespace string' => ['   '];
        yield 'float' => [1.5];
        yield 'boolean' => [true];
        yield 'array' => [['id']];
        yield 'object' => [(object) ['id' => 1]];
    }

    #[DataProvider('invalidResourceIdentifiers')]
    public function testClusterCreateRejectsInvalidRelationshipIdentifierForms(mixed $id): void
    {
        $document = json_decode(
            self::clusterCreateDetail(self::mysqlResource('cluster-1'), includeDefault: false),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($document);
        self::assertIsArray($document['data']);
        $document['data']['relationships'] = [
            'databases' => ['data' => [['type' => 'databaseSchemas', 'id' => $id]]],
        ];

        $this->expectException(CloudResponseException::class);
        $this->client([new MockResponse(json_encode($document, JSON_THROW_ON_ERROR), ['http_code' => 201])])
            ->createDatabaseCluster(new CreateDatabaseClusterRequest(
                'Primary',
                'laravel_mysql_8',
                'eu-central-1',
                new CreateLaravelMySqlConfiguration('db-flex.m-1vcpu-512mb', 5, 1, false, false),
            ));
    }

    /** @return iterable<string, array{mixed}> */
    public static function malformedDefaultRelationships(): iterable
    {
        yield 'missing relationship' => [null];
        yield 'zero children' => [[]];
        yield 'multiple children' => [[
            ['type' => 'databaseSchemas', 'id' => 'default-database'],
            ['type' => 'databaseSchemas', 'id' => 'another-database'],
        ]];
        yield 'wrong type' => [[['type' => 'databases', 'id' => 'default-database']]];
        yield 'duplicate identity' => [[
            ['type' => 'databaseSchemas', 'id' => 200],
            ['type' => 'databaseSchemas', 'id' => '200'],
        ]];
        yield 'empty identity' => [[['type' => 'databaseSchemas', 'id' => '']]];
    }

    #[DataProvider('malformedDefaultRelationships')]
    public function testClusterCreateRejectsMalformedDefaultDatabaseRelationship(mixed $relationshipData): void
    {
        $document = json_decode(
            self::clusterCreateDetail(self::mysqlResource('cluster-1')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($document);
        self::assertIsArray($document['data']);
        if ($relationshipData === null) {
            unset($document['data']['relationships']);
        } else {
            $document['data']['relationships'] = ['databases' => ['data' => $relationshipData]];
        }

        $this->expectException(CloudResponseException::class);
        $this->client([new MockResponse(json_encode($document, JSON_THROW_ON_ERROR), ['http_code' => 201])])
            ->createDatabaseCluster(new CreateDatabaseClusterRequest(
                'Primary',
                'laravel_mysql_8',
                'eu-central-1',
                new CreateLaravelMySqlConfiguration('db-flex.m-1vcpu-512mb', 5, 1, false, false),
            ));
    }

    public function testClusterCreateAcceptsValidRelationshipWithoutIncludedDefault(): void
    {
        $created = $this->client([new MockResponse(
            self::clusterCreateDetail(self::mysqlResource('cluster-1'), false),
            ['http_code' => 201],
        )])->createDatabaseCluster(new CreateDatabaseClusterRequest(
            'Primary',
            'laravel_mysql_8',
            'eu-central-1',
            new CreateLaravelMySqlConfiguration('db-flex.m-1vcpu-512mb', 5, 1, false, false),
        ));

        self::assertSame('default-database', $created->defaultDatabaseId);
        self::assertNull($created->defaultDatabaseName);
    }

    /** @return iterable<string, array{string}> */
    public static function conflictingIncludedDefaults(): iterable
    {
        yield 'wrong resource type' => ['wrong_type'];
        yield 'wrong parent' => ['wrong_parent'];
        yield 'duplicate included identity' => ['duplicate'];
        yield 'unlinked included database' => ['unlinked'];
    }

    #[DataProvider('conflictingIncludedDefaults')]
    public function testClusterCreateRejectsConflictingIncludedDefault(string $conflict): void
    {
        $document = match ($conflict) {
            'wrong_type' => self::clusterCreateDetail(
                self::mysqlResource('cluster-1'),
                includedType: 'databases',
            ),
            'wrong_parent' => self::clusterCreateDetail(
                self::mysqlResource('cluster-1'),
                includedParentId: 'other-cluster',
            ),
            'duplicate' => self::clusterCreateDetail(
                self::mysqlResource('cluster-1'),
                duplicateIncluded: true,
            ),
            'unlinked' => self::clusterCreateDetail(
                self::mysqlResource('cluster-1'),
                includedId: 'another-database',
            ),
            default => throw new \LogicException('Unknown fixture conflict.'),
        };

        $this->expectException(CloudResponseException::class);
        $this->client([new MockResponse($document, ['http_code' => 201])])
            ->createDatabaseCluster(new CreateDatabaseClusterRequest(
                'Primary',
                'laravel_mysql_8',
                'eu-central-1',
                new CreateLaravelMySqlConfiguration('db-flex.m-1vcpu-512mb', 5, 1, false, false),
            ));
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

    public function testLogicalDatabaseCreateCanonicalizesNumericResourceId(): void
    {
        $created = $this->client([new MockResponse(
            self::detail(self::databaseResource(300, 'application')),
            ['http_code' => 201],
        )])->createDatabase('cluster-1', new CreateDatabaseRequest('application'));

        self::assertSame('300', $created->id);
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
                    self::clusterCreateDetail(self::mysqlResource('cluster-1')),
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

    public function testLogicalDatabaseDeleteTargetsExactNestedIdsWithNoBodyAndAcceptsOnly204(): void
    {
        $response = new MockResponse('', ['http_code' => 204]);
        $client = $this->client([$response]);

        $client->deleteDatabase('cluster/exact', 'database/exact');

        self::assertSame(
            'https://cloud.laravel.com/api/databases/clusters/cluster%2Fexact/databases/database%2Fexact',
            $response->getRequestUrl(),
        );
        self::assertSame('DELETE', $response->getRequestMethod());
        self::assertArrayNotHasKey('body', $response->getRequestOptions());
        $this->expectException(CloudResponseException::class);
        $this->client([new MockResponse('{}', ['http_code' => 200])])
            ->deleteDatabase('cluster-1', 'database-1');
    }

    public function testLogicalDatabaseDeleteMapsDocumentedErrorsSafely(): void
    {
        foreach ([
            403 => CloudAuthenticationException::class,
            404 => CloudResourceNotFoundException::class,
            422 => CloudValidationException::class,
        ] as $status => $expected) {
            try {
                $this->client([new MockResponse('{"message":"safe failure"}', ['http_code' => $status])])
                    ->deleteDatabase('cluster-1', 'database-1');
                self::fail('Expected logical Database DELETE failure.');
            } catch (CloudApiException $exception) {
                self::assertInstanceOf($expected, $exception);
                self::assertSame('DELETE', $exception->method);
                self::assertSame('/databases/clusters/cluster-1/databases/database-1', $exception->path);
            }
        }
    }

    public function testLogicalDatabaseDeleteTransportFailureIsUncertainAndNeverRetried(): void
    {
        $calls = 0;
        $http = new MockHttpClient(static function () use (&$calls): never {
            ++$calls;
            throw new TransportException('connection reset');
        });

        try {
            (new SymfonyLaravelCloudClient($http, new CloudApiToken('secret-token')))
                ->deleteDatabase('cluster-1', 'database-1');
            self::fail('Expected uncertain logical Database DELETE failure.');
        } catch (CloudTransportException $exception) {
            self::assertSame('DELETE', $exception->method);
            self::assertStringContainsString('uncertain', $exception->getMessage());
        }

        self::assertSame(1, $calls);
    }

    public function testDatabaseClusterDeleteTargetsExactIdWithNoBodyAndAcceptsOnly204(): void
    {
        $response = new MockResponse('', ['http_code' => 204]);
        $this->client([$response])->deleteDatabaseCluster('cluster/exact');

        self::assertSame('https://cloud.laravel.com/api/databases/clusters/cluster%2Fexact', $response->getRequestUrl());
        self::assertSame('DELETE', $response->getRequestMethod());
        self::assertArrayNotHasKey('body', $response->getRequestOptions());

        $this->expectException(CloudResponseException::class);
        $this->client([new MockResponse('{}', ['http_code' => 200])])->deleteDatabaseCluster('cluster-1');
    }

    public function testDatabaseClusterDeleteIsNeverRetriedAfterTransportFailure(): void
    {
        $calls = 0;
        $http = new MockHttpClient(static function () use (&$calls): never {
            ++$calls;
            throw new TransportException('connection reset');
        });
        try {
            (new SymfonyLaravelCloudClient($http, new CloudApiToken('secret-token')))->deleteDatabaseCluster('cluster-1');
            self::fail('Expected uncertain Database Cluster DELETE failure.');
        } catch (CloudTransportException $exception) {
            self::assertSame('DELETE', $exception->method);
        }
        self::assertSame(1, $calls);
    }

    public function testDatabaseClusterDeleteMapsDocumentedRefusalAndAbsenceResponses(): void
    {
        foreach ([
            403 => CloudAuthenticationException::class,
            404 => CloudResourceNotFoundException::class,
            422 => CloudValidationException::class,
            500 => CloudApiException::class,
        ] as $status => $expected) {
            try {
                $this->client([new MockResponse('{"message":"safe failure"}', ['http_code' => $status])])
                    ->deleteDatabaseCluster('cluster-1');
                self::fail('Expected Database Cluster DELETE failure.');
            } catch (CloudApiException $exception) {
                self::assertInstanceOf($expected, $exception);
                self::assertSame('DELETE', $exception->method);
                self::assertSame('/databases/clusters/cluster-1', $exception->path);
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
        self::assertFalse($cluster->childDiscoveryComplete);
        self::assertSame(['databases'], $cluster->missingRelationships);
        self::assertStringNotContainsString(self::SECRET, serialize($cluster));
    }

    public function testMissingOrMalformedClusterLifecycleStatusIsRejectedSafely(): void
    {
        $missing = self::withoutAttribute(self::mysqlResource('cluster-1'), 'status');
        $malformed = self::withAttribute(self::mysqlResource('cluster-1'), 'status', ['secret' => self::SECRET]);

        foreach ([$missing, $malformed] as $resource) {
            try {
                $this->client([new MockResponse(self::detail($resource))])->databaseCluster('cluster-1');
                self::fail('Expected malformed lifecycle status failure.');
            } catch (CloudResponseException $exception) {
                self::assertStringNotContainsString(self::SECRET, serialize($exception));
            }
        }
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

    public function testLogicalDatabaseDetailPreservesCompleteParentAndEnvironmentRelationships(): void
    {
        $resource = self::withRelationships(self::databaseResource('database-1', 'application'), [
            'database' => ['data' => ['type' => 'databases', 'id' => 'cluster-1']],
            'environments' => ['data' => [
                ['type' => 'environments', 'id' => 'env-2'],
                ['type' => 'environments', 'id' => 'env-1'],
            ]],
        ]);

        $response = new MockResponse(self::detail($resource));
        $database = $this->client([$response])
            ->databaseWithDestructiveRelationships('cluster-1', 'database-1');

        self::assertSame('cluster-1', $database->relationshipClusterId);
        self::assertSame(['env-1', 'env-2'], $database->environmentIds);
        self::assertTrue($database->destructiveRelationshipsComplete);
        self::assertSame([], $database->missingRelationships);
        self::assertSame([], $database->unknownRelationships);
        self::assertSame(
            'https://cloud.laravel.com/api/databases/clusters/cluster-1/databases/database-1?include=database,environments',
            $response->getRequestUrl(),
        );
    }

    public function testLogicalDatabaseExplicitEmptyEnvironmentsIsComplete(): void
    {
        $resource = self::withRelationships(self::databaseResource('database-1', 'application'), [
            'database' => ['data' => ['type' => 'databases', 'id' => 'cluster-1']],
            'environments' => ['data' => []],
        ]);

        $database = $this->client([new MockResponse(self::detail($resource))])
            ->databaseWithDestructiveRelationships('cluster-1', 'database-1');

        self::assertTrue($database->destructiveRelationshipsComplete);
        self::assertSame([], $database->environmentIds);
    }

    public function testMissingAndMalformedLogicalDatabaseRelationshipsRemainIncomplete(): void
    {
        $missing = $this->client([new MockResponse(self::detail(self::databaseResource('database-1', 'application')))])
            ->databaseWithDestructiveRelationships('cluster-1', 'database-1');
        self::assertFalse($missing->destructiveRelationshipsComplete);
        self::assertSame(['database', 'environments'], $missing->missingRelationships);

        $malformedResource = self::withRelationships(self::databaseResource('database-1', 'application'), [
            'database' => ['data' => ['type' => 'databases', 'id' => 'cluster-1']],
            'environments' => ['data' => [['type' => 'environments', 'id' => self::SECRET], 'bad']],
        ]);
        $malformed = $this->client([new MockResponse(self::detail($malformedResource))])
            ->databaseWithDestructiveRelationships('cluster-1', 'database-1');
        self::assertFalse($malformed->destructiveRelationshipsComplete);
        self::assertSame(['environments'], $malformed->unknownRelationships);
        self::assertStringNotContainsString(self::SECRET, serialize($malformed));
    }

    public function testClusterDetailPreservesCompleteChildrenAndMalformedEvidenceIsIncomplete(): void
    {
        $completeResource = self::withRelationships(self::mysqlResource('cluster-1'), [
            'databases' => ['data' => [
                ['type' => 'databaseSchemas', 'id' => 'database-2'],
                ['type' => 'databaseSchemas', 'id' => 'database-1'],
            ]],
        ]);
        $completeResponse = new MockResponse(self::detail($completeResource));
        $complete = $this->client([$completeResponse])->databaseCluster('cluster-1');
        self::assertSame(['database-1', 'database-2'], $complete->databaseIds);
        self::assertTrue($complete->childDiscoveryComplete);
        self::assertStringContainsString('include=databases', $completeResponse->getRequestUrl());

        $malformedResource = self::withRelationships(self::mysqlResource('cluster-1'), [
            'databases' => ['data' => [['type' => 'wrong', 'id' => self::SECRET]]],
        ]);
        $malformed = $this->client([new MockResponse(self::detail($malformedResource))])
            ->databaseCluster('cluster-1');
        self::assertFalse($malformed->childDiscoveryComplete);
        self::assertSame(['databases'], $malformed->unknownRelationships);
        self::assertStringNotContainsString(self::SECRET, serialize($malformed));
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
        self::assertStringContainsString(
            'include=application,branch,deployments,currentDeployment,primaryDomain,instances,database,cache,buckets,websocketApplication,secrets',
            $response->getRequestUrl(),
        );
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
    private static function mysqlResource(string|int $id): array
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

    /**
     * @param array<string, mixed> $resource
     * @return array<string, mixed>
     */
    private static function withoutAttribute(array $resource, string $name): array
    {
        $attributes = $resource['attributes'] ?? null;
        self::assertIsArray($attributes);
        unset($attributes[$name]);
        $resource['attributes'] = $attributes;
        return $resource;
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
    private static function databaseResource(mixed $id, string $name): array
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

    /** @param array<string, mixed> $resource */
    private static function clusterCreateDetail(
        array $resource,
        bool $includeDefault = true,
        string $includedType = 'databaseSchemas',
        mixed $includedParentId = null,
        bool $duplicateIncluded = false,
        mixed $includedId = 'default-database',
        mixed $relationshipId = 'default-database',
    ): string {
        $resource['relationships'] = [
            'databases' => [
                'data' => [['type' => 'databaseSchemas', 'id' => $relationshipId]],
            ],
        ];
        $default = self::databaseResource($includedId, 'safe-informational-name');
        $default['type'] = $includedType;
        $default['relationships'] = [
            'database' => ['data' => [
                'type' => 'databaseClusters',
                'id' => $includedParentId ?? $resource['id'],
            ]],
        ];
        $included = $duplicateIncluded ? [$default, $default] : [$default];

        return json_encode([
            'data' => $resource,
            ...($includeDefault ? ['included' => $included] : []),
        ], JSON_THROW_ON_ERROR);
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

    /**
     * @param array<string, mixed> $resource
     * @param array<string, mixed> $relationships
     * @return array<string, mixed>
     */
    private static function withRelationships(array $resource, array $relationships): array
    {
        $resource['relationships'] = $relationships;
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
