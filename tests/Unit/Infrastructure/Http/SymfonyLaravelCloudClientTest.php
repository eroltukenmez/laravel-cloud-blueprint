<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Infrastructure\Http;

use LaravelCloudBlueprint\Cloud\CloudApiToken;
use LaravelCloudBlueprint\Blueprint\SourceProvider;
use LaravelCloudBlueprint\Cloud\DTO\CreateApplicationRequest;
use LaravelCloudBlueprint\Cloud\DTO\CreateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\DTO\EnvironmentVariableInput;
use LaravelCloudBlueprint\Cloud\DTO\EnvironmentVariableMutationMethod;
use LaravelCloudBlueprint\Cloud\DTO\EnvironmentDependencyType;
use LaravelCloudBlueprint\Cloud\DTO\EnvironmentDestructiveReadiness;
use LaravelCloudBlueprint\Cloud\DTO\DatabaseSnapshotStatus;
use LaravelCloudBlueprint\Cloud\DTO\DatabaseSnapshotType;
use LaravelCloudBlueprint\Cloud\DTO\SetEnvironmentVariablesRequest;
use LaravelCloudBlueprint\Cloud\DTO\UpdateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\Exception\CloudApiException;
use LaravelCloudBlueprint\Cloud\Exception\CloudAuthenticationException;
use LaravelCloudBlueprint\Cloud\Exception\CloudRateLimitException;
use LaravelCloudBlueprint\Cloud\Exception\CloudResourceNotFoundException;
use LaravelCloudBlueprint\Cloud\Exception\CloudResponseException;
use LaravelCloudBlueprint\Cloud\Exception\CloudTransportException;
use LaravelCloudBlueprint\Cloud\Exception\CloudValidationException;
use LaravelCloudBlueprint\Infrastructure\Http\SymfonyLaravelCloudClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SymfonyLaravelCloudClientTest extends TestCase
{
    public function testDatabaseSnapshotsUseExactClusterScopeAndCompletePagination(): void
    {
        $first = new MockResponse(<<<'JSON'
{"data":[{"id":"snapshot-manual","type":"database-snapshots","attributes":{"type":"manual","status":"available","pitr_enabled":false,"password":"must-not-be-retained"},"relationships":{"database":{"data":{"type":"databases","id":"cluster-1"}}}}],"links":{"next":"https://cloud.laravel.com/api/databases/clusters/cluster-1/snapshots?page=2"},"meta":{"current_page":1,"last_page":2}}
JSON);
        $second = new MockResponse(<<<'JSON'
{"data":[{"id":"snapshot-scheduled","type":"database-snapshots","attributes":{"type":"scheduled","status":"pending","pitr_enabled":true},"relationships":{"database":{"data":{"type":"databases","id":"cluster-1"}}}},{"id":"snapshot-future","type":"database-snapshots","attributes":{"type":"manual","status":"future-status","pitr_enabled":false},"relationships":{"database":{"data":{"type":"databases","id":"cluster-1"}}}}],"links":{"next":null},"meta":{"current_page":2,"last_page":2}}
JSON);

        $snapshots = $this->client([$first, $second])->databaseSnapshots('cluster-1');

        $byId = [];
        foreach ($snapshots as $snapshot) {
            $byId[$snapshot->id] = $snapshot;
        }

        self::assertCount(3, $snapshots);
        self::assertSame(DatabaseSnapshotType::MANUAL, $byId['snapshot-manual']->type);
        self::assertSame(DatabaseSnapshotStatus::AVAILABLE, $byId['snapshot-manual']->status);
        self::assertSame(DatabaseSnapshotType::SCHEDULED, $byId['snapshot-scheduled']->type);
        self::assertSame(DatabaseSnapshotStatus::PENDING, $byId['snapshot-scheduled']->status);
        self::assertTrue($byId['snapshot-scheduled']->pointInTimeRecoveryEnabled);
        self::assertNull($byId['snapshot-future']->status);
        self::assertStringContainsString('/databases/clusters/cluster-1/snapshots', $first->getRequestUrl());
        self::assertSame('GET', $first->getRequestMethod());
    }

    public function testDatabaseSnapshotsAcceptAuthoritativeCompleteEmptyPage(): void
    {
        $response = new MockResponse('{"data":[],"links":{"next":null},"meta":{"current_page":1,"last_page":1}}');

        self::assertSame([], $this->client([$response])->databaseSnapshots('cluster-1'));
    }

    #[DataProvider('malformedSnapshotResponses')]
    public function testMalformedSnapshotEvidenceIsRejected(string $response): void
    {
        $this->expectException(CloudResponseException::class);
        $this->client([new MockResponse($response)])->databaseSnapshots('cluster-1');
    }

    /** @return iterable<string, array{string}> */
    public static function malformedSnapshotResponses(): iterable
    {
        $page = static fn (string $resource): string => sprintf(
            '{"data":[%s],"links":{"next":null},"meta":{"current_page":1,"last_page":1}}',
            $resource,
        );
        yield 'missing status' => [$page('{"id":"snapshot-1","attributes":{"type":"manual","pitr_enabled":false},"relationships":{"database":{"data":{"type":"databases","id":"cluster-1"}}}}')];
        yield 'unknown type' => [$page('{"id":"snapshot-1","attributes":{"type":"future-type","status":"pending","pitr_enabled":false},"relationships":{"database":{"data":{"type":"databases","id":"cluster-1"}}}}')];
        yield 'parent mismatch' => [$page('{"id":"snapshot-1","attributes":{"type":"manual","status":"pending","pitr_enabled":false},"relationships":{"database":{"data":{"type":"databases","id":"different-cluster"}}}}')];
        yield 'malformed parent' => [$page('{"id":"snapshot-1","attributes":{"type":"manual","status":"pending","pitr_enabled":false},"relationships":{"database":{"data":null}}}')];
    }

    #[DataProvider('incompleteSnapshotPaginationResponses')]
    public function testIncompleteOrMalformedSnapshotPaginationIsRejected(string $response): void
    {
        $this->expectException(CloudResponseException::class);
        $this->client([new MockResponse($response)])->databaseSnapshots('cluster-1');
    }

    /** @return iterable<string, array{string}> */
    public static function incompleteSnapshotPaginationResponses(): iterable
    {
        yield 'missing metadata' => ['{"data":[],"links":{"next":null}}'];
        yield 'premature end' => ['{"data":[],"links":{"next":null},"meta":{"current_page":1,"last_page":2}}'];
        yield 'invalid page' => ['{"data":[],"links":{"next":null},"meta":{"current_page":2,"last_page":1}}'];
        yield 'continues after end' => ['{"data":[],"links":{"next":"https://cloud.laravel.com/api/databases/clusters/cluster-1/snapshots?page=2"},"meta":{"current_page":1,"last_page":1}}'];
        yield 'off-origin next URL' => ['{"data":[],"links":{"next":"https://example.com/api/databases/clusters/cluster-1/snapshots?page=2"},"meta":{"current_page":1,"last_page":2}}'];
    }

    public function testCyclicSnapshotPaginationIsRejected(): void
    {
        $first = '{"data":[],"links":{"next":"https://cloud.laravel.com/api/databases/clusters/cluster-1/snapshots?page=2"},"meta":{"current_page":1,"last_page":3}}';
        $second = '{"data":[],"links":{"next":"https://cloud.laravel.com/api/databases/clusters/cluster-1/snapshots?page=2"},"meta":{"current_page":2,"last_page":3}}';

        $this->expectException(CloudResponseException::class);
        $this->client([new MockResponse($first), new MockResponse($second)])->databaseSnapshots('cluster-1');
    }

    public function testOrganizationMapsToATypedObjectAndSendsSafeRequiredHeaders(): void
    {
        $response = new MockResponse(self::fixture('organization.json'));
        $request = new RecordedRequest();
        $http = new MockHttpClient(function (string $method, string $url) use ($request, $response): MockResponse {
            $request->method = $method;
            $request->url = $url;
            return $response;
        });

        $organization = (new SymfonyLaravelCloudClient($http, new CloudApiToken('secret-token')))->organization();

        self::assertSame('org-1', $organization->id);
        self::assertSame('Acme Organization', $organization->name);
        self::assertSame('acme', $organization->slug);
        self::assertSame('GET', $request->method);
        self::assertSame('https://cloud.laravel.com/api/meta/organization', $request->url);

        $options = $response->getRequestOptions();
        self::assertIsArray($options['normalized_headers']);
        $headers = $options['normalized_headers'];
        self::assertIsArray($headers['authorization']);
        self::assertIsArray($headers['accept']);
        self::assertIsArray($headers['user-agent']);
        self::assertContains('Authorization: Bearer secret-token', $headers['authorization']);
        self::assertContains('Accept: application/json', $headers['accept']);
        self::assertContains('User-Agent: Laravel-Cloud-Blueprint/0.1.0-alpha.9', $headers['user-agent']);
    }

    public function testApplicationsMapNullableFieldsAndFollowPagination(): void
    {
        $client = $this->client([
            new MockResponse(self::fixture('applications-page-1.json')),
            new MockResponse(self::fixture('applications-page-2.json')),
        ]);

        $applications = $client->applications();

        self::assertCount(2, $applications);
        self::assertSame('API', $applications[0]->name);
        self::assertSame('api', $applications[0]->slug);
        self::assertSame('eu-central-1', $applications[0]->region);
        self::assertSame('acme/api', $applications[0]->repository);
        self::assertSame(SourceProvider::GITHUB, $applications[0]->sourceProvider);
        self::assertNull($applications[1]->slug);
        self::assertNull($applications[1]->repository);
    }

    public function testEnvironmentsMapApplicationAndOptionalBranch(): void
    {
        $environments = $this->client([new MockResponse(self::fixture('environments.json'))])
            ->environments('app-1');

        self::assertCount(2, $environments);
        self::assertSame('env-1', $environments[0]->id);
        self::assertSame('app-1', $environments[0]->applicationId);
        self::assertSame('production', $environments[0]->name);
        self::assertSame('main', $environments[0]->branch);
        self::assertNull($environments[1]->branch);
        self::assertSame(EnvironmentDestructiveReadiness::UNKNOWN, $environments[0]->dependencies->readiness());
    }

    public function testEnvironmentListMapsSafeDependencySignalsWithoutAdditionalRequests(): void
    {
        $response = new MockResponse(self::fixture('environment-dependencies.json'));
        $environments = $this->client([$response])->environments('app-1');

        self::assertCount(1, $environments);
        $dependencies = $environments[0]->dependencies;
        self::assertTrue($dependencies->complete);
        self::assertSame(EnvironmentDestructiveReadiness::BLOCKED, $dependencies->readiness());
        self::assertSame([
            EnvironmentDependencyType::DATABASE_ATTACHMENT,
            EnvironmentDependencyType::CACHE_ATTACHMENT,
            EnvironmentDependencyType::WEBSOCKET_ATTACHMENT,
            EnvironmentDependencyType::CUSTOM_DOMAIN,
            EnvironmentDependencyType::INSTANCE,
            EnvironmentDependencyType::DEPLOYMENT,
            EnvironmentDependencyType::SECRET,
            EnvironmentDependencyType::FILESYSTEM,
            EnvironmentDependencyType::DEFAULT_ENVIRONMENT,
        ], $dependencies->categories());
        self::assertSame([
            EnvironmentDependencyType::INSTANCE,
        ], $dependencies->informationalCategories());
        self::assertNotContains(EnvironmentDependencyType::INSTANCE, $dependencies->blockingCategories());
        self::assertSame('GET', $response->getRequestMethod());
        self::assertStringContainsString('include=application,branch,deployments', $response->getRequestUrl());
    }

    public function testCompleteEmptyRelationshipsAreSafeWhileUnknownRelationshipsRemainUnknown(): void
    {
        $empty = <<<'JSON'
{"data":[{"id":"env-1","type":"environments","attributes":{"name":"preview"},"relationships":{"application":{"data":{"type":"applications","id":"app-1"}},"branch":{"data":null},"deployments":{"data":[]},"currentDeployment":{"data":null},"domains":{"data":[]},"primaryDomain":{"data":null},"instances":{"data":[]},"database":{"data":null},"cache":{"data":null},"buckets":{"data":[]},"websocketApplication":{"data":null},"secrets":{"data":[]}}}],"included":[{"id":"app-1","type":"applications","attributes":{"name":"API"},"relationships":{"defaultEnvironment":{"data":{"type":"environments","id":"env-other"}}}}],"links":{"next":null}}
JSON;
        $safe = $this->client([new MockResponse($empty)])->environments('app-1')[0]->dependencies;
        self::assertTrue($safe->complete);
        self::assertSame(EnvironmentDestructiveReadiness::SAFE, $safe->readiness());
        self::assertNull($safe->databaseId);
        self::assertSame(0, $safe->filesystemCount);
        self::assertSame([], $safe->missingRelationships);

        $unknown = str_replace('"secrets":{"data":[]}', '"secrets":{"data":[]},"futureDependency":{"data":null}', $empty);
        $incomplete = $this->client([new MockResponse($unknown)])->environments('app-1')[0]->dependencies;
        self::assertFalse($incomplete->complete);
        self::assertSame(['futureDependency'], $incomplete->unknownRelationships);
        self::assertSame(EnvironmentDestructiveReadiness::UNKNOWN, $incomplete->readiness());
    }

    public function testOfficialNormalEnvironmentShapeWithOnlyInstanceIsCompleteAndSafe(): void
    {
        $payload = <<<'JSON'
{"data":[{"id":"env-1","type":"environments","attributes":{"name":"preview"},"relationships":{"application":{"data":{"type":"applications","id":"app-1"}},"branch":{"data":null},"deployments":{"data":[]},"currentDeployment":{"data":null},"domains":{"data":[]},"primaryDomain":{"data":null},"instances":{"data":[{"type":"instances","id":"instance-1"}]},"database":{"data":null},"cache":{"data":null},"buckets":{"data":[]},"websocketApplication":{"data":null},"secrets":{"data":[]}}}],"included":[{"id":"app-1","type":"applications","attributes":{"name":"API"},"relationships":{"defaultEnvironment":{"data":{"type":"environments","id":"env-other"}}}}],"links":{"next":null}}
JSON;

        $dependencies = $this->client([new MockResponse($payload)])->environments('app-1')[0]->dependencies;

        self::assertTrue($dependencies->complete);
        self::assertSame(EnvironmentDestructiveReadiness::SAFE, $dependencies->readiness());
        self::assertSame([EnvironmentDependencyType::INSTANCE], $dependencies->informationalCategories());
        self::assertSame([], $dependencies->blockingCategories());
    }

    public function testMissingRequiredDependencyRelationshipIsDiagnosedAndUnknown(): void
    {
        $payload = str_replace(
            ',"secrets":{"data":[]}',
            '',
            <<<'JSON'
{"data":[{"id":"env-1","type":"environments","attributes":{"name":"preview"},"relationships":{"application":{"data":{"type":"applications","id":"app-1"}},"branch":{"data":null},"deployments":{"data":[]},"currentDeployment":{"data":null},"domains":{"data":[]},"primaryDomain":{"data":null},"instances":{"data":[]},"database":{"data":null},"cache":{"data":null},"buckets":{"data":[]},"websocketApplication":{"data":null},"secrets":{"data":[]}}}],"included":[{"id":"app-1","type":"applications","attributes":{"name":"API"},"relationships":{"defaultEnvironment":{"data":null}}}],"links":{"next":null}}
JSON,
        );

        $dependencies = $this->client([new MockResponse($payload)])->environments('app-1')[0]->dependencies;

        self::assertFalse($dependencies->complete);
        self::assertSame(['secrets'], $dependencies->missingRelationships);
        self::assertSame(EnvironmentDestructiveReadiness::UNKNOWN, $dependencies->readiness());
    }

    public function testMissingIncludedDefaultEnvironmentUsesAuthoritativeApplicationLinkage(): void
    {
        $payload = <<<'JSON'
{"data":[{"id":"env-1","type":"environments","attributes":{"name":"preview"},"relationships":{"application":{"data":{"type":"applications","id":"app-1"}},"branch":{"data":null},"deployments":{"data":[]},"currentDeployment":{"data":null},"domains":{"data":[]},"primaryDomain":{"data":null},"instances":{"data":[]},"database":{"data":null},"cache":{"data":null},"buckets":{"data":[]},"websocketApplication":{"data":null},"secrets":{"data":[]}}}],"included":[{"id":"app-1","type":"applications","attributes":{"name":"API"},"relationships":{}}],"links":{"next":null}}
JSON;

        $application = <<<'JSON'
{"data":{"id":"app-1","type":"applications","relationships":{"defaultEnvironment":{"data":{"type":"environments","id":"env-other"}}}}}
JSON;
        $dependencies = $this->client([
            new MockResponse($payload),
            new MockResponse($application),
        ])->environments('app-1')[0]->dependencies;

        self::assertSame([], $dependencies->missingRelationships);
        self::assertFalse($dependencies->isDefaultEnvironment);
        self::assertSame(EnvironmentDestructiveReadiness::SAFE, $dependencies->readiness());
    }

    public function testMissingDomainLinkageUsesScopedDomainListAsAuthoritativeKnownEmpty(): void
    {
        $payload = <<<'JSON'
{"data":[{"id":"env-1","type":"environments","attributes":{"name":"preview"},"relationships":{"application":{"data":{"type":"applications","id":"app-1"}},"branch":{"data":null},"deployments":{"data":[]},"currentDeployment":{"data":null},"primaryDomain":{"data":null},"instances":{"data":[{"type":"instances","id":"instance-1"}]},"database":{"data":null},"cache":{"data":null},"buckets":{"data":[]},"websocketApplication":{"data":null},"secrets":{"data":[]}}}],"included":[{"id":"app-1","type":"applications","relationships":{"defaultEnvironment":{"data":{"type":"environments","id":"env-other"}}}}],"links":{"next":null}}
JSON;
        $domains = new MockResponse('{"data":[],"links":{"next":null}}');

        $dependencies = $this->client([
            new MockResponse($payload),
            $domains,
        ])->environments('app-1')[0]->dependencies;

        self::assertTrue($dependencies->complete);
        self::assertSame(0, $dependencies->domainCount);
        self::assertSame([], $dependencies->missingRelationships);
        self::assertSame(EnvironmentDestructiveReadiness::SAFE, $dependencies->readiness());
        self::assertStringContainsString('/environments/env-1/domains', $domains->getRequestUrl());
    }

    public function testMissingDomainLinkageUsesScopedDomainListAsAuthoritativeBlocker(): void
    {
        $payload = <<<'JSON'
{"data":[{"id":"env-1","type":"environments","attributes":{"name":"preview"},"relationships":{"application":{"data":{"type":"applications","id":"app-1"}},"branch":{"data":null},"deployments":{"data":[]},"currentDeployment":{"data":null},"primaryDomain":{"data":null},"instances":{"data":[]},"database":{"data":null},"cache":{"data":null},"buckets":{"data":[]},"websocketApplication":{"data":null},"secrets":{"data":[]}}}],"included":[{"id":"app-1","type":"applications","relationships":{"defaultEnvironment":{"data":{"type":"environments","id":"env-other"}}}}],"links":{"next":null}}
JSON;
        $domains = '{"data":[{"id":"domain-1","type":"domains"}],"links":{"next":null}}';

        $dependencies = $this->client([
            new MockResponse($payload),
            new MockResponse($domains),
        ])->environments('app-1')[0]->dependencies;

        self::assertSame(1, $dependencies->domainCount);
        self::assertSame(EnvironmentDestructiveReadiness::BLOCKED, $dependencies->readiness());
        self::assertContains(EnvironmentDependencyType::CUSTOM_DOMAIN, $dependencies->blockingCategories());
    }

    public function testApplicationFallbackExactDefaultIdentityAndNullAreAuthoritative(): void
    {
        $environment = <<<'JSON'
{"data":{"id":"env-1","type":"environments","attributes":{"name":"preview"},"relationships":{"application":{"data":{"type":"applications","id":"app-1"}},"branch":{"data":null},"deployments":{"data":[]},"currentDeployment":{"data":null},"domains":{"data":[]},"primaryDomain":{"data":null},"instances":{"data":[]},"database":{"data":null},"cache":{"data":null},"buckets":{"data":[]},"websocketApplication":{"data":null},"secrets":{"data":[]}}}}
JSON;
        foreach ([
            ['{"type":"environments","id":"env-1"}', true, EnvironmentDestructiveReadiness::BLOCKED],
            ['null', false, EnvironmentDestructiveReadiness::SAFE],
        ] as [$linkage, $expectedDefault, $readiness]) {
            $application = sprintf(
                '{"data":{"id":"app-1","type":"applications","relationships":{"defaultEnvironment":{"data":%s}}}}',
                $linkage,
            );
            $dependencies = $this->client([
                new MockResponse($environment),
                new MockResponse($application),
            ])->environment('env-1')->dependencies;

            self::assertSame($expectedDefault, $dependencies->isDefaultEnvironment);
            self::assertSame($readiness, $dependencies->readiness());
        }
    }

    public function testRealCloudEquivalentMissingLinkagesUseScopedFallbacksAndBecomeSafe(): void
    {
        $environment = <<<'JSON'
{"data":{"id":"env-1","type":"environments","attributes":{"name":"delete-e2e"},"relationships":{"application":{"data":{"type":"applications","id":"app-1"}},"branch":{"data":null},"deployments":{"data":[]},"currentDeployment":{"data":null},"primaryDomain":{"data":null},"instances":{"data":[{"type":"instances","id":"instance-1"}]},"database":{"data":null},"cache":{"data":null},"buckets":{"data":[]},"websocketApplication":{"data":null},"secrets":{"data":[]}}},"included":[{"id":"app-1","type":"applications","relationships":{}}]}
JSON;
        $domains = new MockResponse('{"data":[],"links":{"next":null}}');
        $application = new MockResponse(
            '{"data":{"id":"app-1","type":"applications","relationships":{"defaultEnvironment":{"data":{"type":"environments","id":"env-production"}}}}}',
        );

        $dependencies = $this->client([
            new MockResponse($environment),
            $domains,
            $application,
        ])->environment('env-1')->dependencies;

        self::assertTrue($dependencies->complete);
        self::assertSame(EnvironmentDestructiveReadiness::SAFE, $dependencies->readiness());
        self::assertSame([EnvironmentDependencyType::INSTANCE], $dependencies->informationalCategories());
        self::assertSame([], $dependencies->blockingCategories());
        self::assertSame([], $dependencies->missingRelationships);
        self::assertStringContainsString('/environments/env-1/domains', $domains->getRequestUrl());
        self::assertStringContainsString('/applications/app-1?include=defaultEnvironment', $application->getRequestUrl());
    }

    public function testMalformedDomainAndApplicationDefaultLinkageRemainNonExecutable(): void
    {
        $malformedDomain = str_replace(
            '"domains":{"data":[]}',
            '"domains":{"data":null}',
            <<<'JSON'
{"data":[{"id":"env-1","type":"environments","attributes":{"name":"preview"},"relationships":{"application":{"data":{"type":"applications","id":"app-1"}},"branch":{"data":null},"deployments":{"data":[]},"currentDeployment":{"data":null},"domains":{"data":[]},"primaryDomain":{"data":null},"instances":{"data":[]},"database":{"data":null},"cache":{"data":null},"buckets":{"data":[]},"websocketApplication":{"data":null},"secrets":{"data":[]}}}],"included":[{"id":"app-1","type":"applications","relationships":{"defaultEnvironment":{"data":null}}}],"links":{"next":null}}
JSON,
        );

        try {
            $this->client([new MockResponse($malformedDomain)])->environments('app-1');
            self::fail('Expected malformed domains linkage failure.');
        } catch (CloudResponseException) {
        }

        $missingDefault = <<<'JSON'
{"data":{"id":"env-1","type":"environments","attributes":{"name":"preview"},"relationships":{"application":{"data":{"type":"applications","id":"app-1"}},"branch":{"data":null},"deployments":{"data":[]},"currentDeployment":{"data":null},"domains":{"data":[]},"primaryDomain":{"data":null},"instances":{"data":[]},"database":{"data":null},"cache":{"data":null},"buckets":{"data":[]},"websocketApplication":{"data":null},"secrets":{"data":[]}}}}
JSON;
        $dependencies = $this->client([
            new MockResponse($missingDefault),
            new MockResponse('{"data":{"id":"app-1","type":"applications","relationships":{}}}'),
        ])->environment('env-1')->dependencies;
        self::assertSame(EnvironmentDestructiveReadiness::UNKNOWN, $dependencies->readiness());
        self::assertSame(['application.defaultEnvironment'], $dependencies->missingRelationships);

        try {
            $this->client([
                new MockResponse($missingDefault),
                new MockResponse('{"data":{"id":"app-1","type":"applications","relationships":{"defaultEnvironment":{}}}}'),
            ])->environment('env-1');
            self::fail('Expected malformed default Environment linkage failure.');
        } catch (CloudResponseException) {
        }
    }

    public function testMalformedSafetyRelationshipFailsInsteadOfBecomingSafe(): void
    {
        $payload = str_replace(
            '"type": "caches", "id": "cache-1"',
            '"type": "databaseSchemas", "id": "cache-1"',
            self::fixture('environment-dependencies.json'),
        );

        $this->expectException(CloudResponseException::class);
        $this->client([new MockResponse($payload)])->environments('app-1');
    }

    public function testEnvironmentDetailsMapTypedVariablesFromTheOfficialResponseShape(): void
    {
        $response = new MockResponse(<<<'JSON'
{"data":{"id":"env-1","type":"environments","attributes":{"name":"production","environment_variables":[{"key":"APP_ENV","value":"production"},{"key":"APP_KEY","value":"remote-secret"}]}}}
JSON);

        $details = $this->client([$response])->environment('env-1');

        self::assertSame('env-1', $details->id);
        self::assertSame('production', $details->name);
        self::assertNotNull($details->variables);
        self::assertCount(2, $details->variables);
        self::assertSame('production', $details->variables->find('APP_ENV')?->value);
        self::assertSame('remote-secret', $details->variables->find('APP_KEY')?->value);
        self::assertSame('GET', $response->getRequestMethod());
        self::assertStringContainsString('/environments/env-1?include=application,branch,deployments', $response->getRequestUrl());
    }

    public function testMissingEnvironmentVariableDataRemainsUnavailable(): void
    {
        $details = $this->client([new MockResponse(
            '{"data":{"id":"env-1","type":"environments","attributes":{"name":"production"}}}',
        )])->environment('env-1');

        self::assertNull($details->variables);
    }

    public function testMalformedEnvironmentVariableShapeFailsWithoutExposingRemoteValues(): void
    {
        $secret = 'must-not-appear-in-error';

        try {
            $this->client([new MockResponse(sprintf(
                '{"data":{"id":"env-1","attributes":{"name":"production","environment_variables":[{"key":123,"value":"%s"}]}}}',
                $secret,
            ))])->environment('env-1');
            self::fail('Expected malformed variable response failure.');
        } catch (CloudResponseException $exception) {
            self::assertStringNotContainsString($secret, $exception->getMessage());
            self::assertStringContainsString('/environments/env-1?include=application,branch,deployments', $exception->path);
        }
    }

    public function testMalformedRequiredFieldsThrowAControlledResponseException(): void
    {
        $this->expectException(CloudResponseException::class);

        $this->client([new MockResponse('{"data":{"attributes":{"name":"Acme","slug":"acme"}}}')])
            ->organization();
    }

    public function testInvalidJsonThrowsAControlledResponseException(): void
    {
        $this->expectException(CloudResponseException::class);

        $this->client([new MockResponse('{invalid-json')])->organization();
    }

    public function testApplicationCreateUsesOfficialPayloadAndMaps201Response(): void
    {
        $response = new MockResponse(self::applicationCreateResponse(), ['http_code' => 201]);
        $client = $this->client([$response]);

        $application = $client->createApplication(new CreateApplicationRequest(
            'my-api', 'acme/my-api', 'eu-central-1', SourceProvider::GITHUB,
        ));

        self::assertSame('app-created', $application->id);
        self::assertSame('my-api', $application->name);
        $options = $response->getRequestOptions();
        self::assertIsString($options['body']);
        self::assertJsonStringEqualsJsonString(
            '{"repository":"acme/my-api","name":"my-api","region":"eu-central-1","source_control_provider_type":"github"}',
            $options['body'],
        );
        self::assertIsArray($options['normalized_headers']);
        self::assertArrayHasKey('content-type', $options['normalized_headers']);
    }

    public function testEnvironmentCreateUsesOfficialPayloadAndMaps201Response(): void
    {
        $response = new MockResponse('{"data":{"id":"env-created","type":"environments","attributes":{"name":"production"}}}', ['http_code' => 201]);

        $environment = $this->client([$response])->createEnvironment(
            'app-created',
            new CreateEnvironmentRequest('production', 'main'),
        );

        self::assertSame('env-created', $environment->id);
        self::assertSame('app-created', $environment->applicationId);
        self::assertSame('main', $environment->branch);
        self::assertSame('{"branch":"main","name":"production"}', $response->getRequestOptions()['body']);
    }

    public function testEnvironmentUpdateAcceptsRealRelationshipBranchResponseAndSendsBranchOnlyPatch(): void
    {
        $response = new MockResponse(
            '{"data":{"id":"env-123","type":"environments","attributes":{"name":"production"},"relationships":{"branch":{"data":{"type":"branches","id":"branch-relationship-id-not-a-name"}}}}}',
            ['http_code' => 200],
        );

        $environment = $this->client([$response])->updateEnvironment(
            'env-123',
            new UpdateEnvironmentRequest('develop'),
        );

        self::assertSame('env-123', $environment->id);
        self::assertSame('PATCH', $response->getRequestMethod());
        self::assertSame('https://cloud.laravel.com/api/environments/env-123', $response->getRequestUrl());
        self::assertSame('{"branch":"develop"}', $response->getRequestOptions()['body']);
    }

    public function testEnvironmentUpdateRequiresOnlyAValidResponseIdentity(): void
    {
        $environment = $this->client([new MockResponse(
            '{"data":{"id":"env-123","type":"environments"}}',
            ['http_code' => 200],
        )])->updateEnvironment('env-123', new UpdateEnvironmentRequest('develop'));

        self::assertSame('env-123', $environment->id);
    }

    public function testEnvironmentUpdateMissingResponseIdentityFailsSafely(): void
    {
        $this->expectException(CloudResponseException::class);

        $this->client([new MockResponse('{"data":{"type":"environments"}}', ['http_code' => 200])])
            ->updateEnvironment('env-123', new UpdateEnvironmentRequest('develop'));
    }

    public function testEnvironmentUpdateInvalidJsonFailsSafely(): void
    {
        $this->expectException(CloudResponseException::class);

        $this->client([new MockResponse('{invalid-json', ['http_code' => 200])])
            ->updateEnvironment('env-123', new UpdateEnvironmentRequest('develop'));
    }

    /** @return iterable<string, array{int, class-string<CloudApiException>}> */
    public static function environmentUpdateErrorProvider(): iterable
    {
        yield 'forbidden' => [403, CloudAuthenticationException::class];
        yield 'not found' => [404, CloudResourceNotFoundException::class];
        yield 'validation' => [422, CloudValidationException::class];
        yield 'server error' => [500, CloudApiException::class];
    }

    /** @param class-string<CloudApiException> $expected */
    #[DataProvider('environmentUpdateErrorProvider')]
    public function testEnvironmentUpdateErrorsMapSafely(int $status, string $expected): void
    {
        try {
            $this->client([new MockResponse('{"message":"failed"}', ['http_code' => $status])])
                ->updateEnvironment('env-1', new UpdateEnvironmentRequest('develop'));
            self::fail('Expected environment update failure.');
        } catch (CloudApiException $exception) {
            self::assertInstanceOf($expected, $exception);
            self::assertSame('PATCH', $exception->method);
            self::assertSame('/environments/env-1', $exception->path);
        }
    }

    public function testEnvironmentPatchTransportFailureIsNotRetried(): void
    {
        $attempts = 0;
        $http = new MockHttpClient(static function () use (&$attempts): never {
            ++$attempts;
            throw new TransportException('timeout after send');
        });

        try {
            (new SymfonyLaravelCloudClient($http, new CloudApiToken('secret-token')))
                ->updateEnvironment('env-1', new UpdateEnvironmentRequest('develop'));
            self::fail('Expected uncertain environment update failure.');
        } catch (CloudTransportException $exception) {
            self::assertSame(1, $attempts);
            self::assertSame('PATCH', $exception->method);
            self::assertStringContainsString('uncertain', $exception->getMessage());
            self::assertStringNotContainsString('secret-token', $exception->getMessage());
        }
    }

    public function testCreateValidationFailureIsControlled(): void
    {
        $response = new MockResponse(json_encode([
            'message' => 'The given data was invalid.',
            'errors' => [
                'region' => ['The selected region is invalid.', 'The region is unavailable.'],
                'repository' => ['The repository could not be found.'],
            ],
        ], JSON_THROW_ON_ERROR), [
            'http_code' => 422,
            'response_headers' => ['X-Request-Id: validation-request-1'],
        ]);

        try {
            $this->client([$response])->createApplication(
                new CreateApplicationRequest('api', 'acme/api', 'eu-central-1', SourceProvider::GITHUB),
            );
            self::fail('Expected validation failure.');
        } catch (CloudValidationException $exception) {
            self::assertSame('Laravel Cloud rejected the request.', $exception->getMessage());
            self::assertSame('The given data was invalid.', $exception->apiMessage);
            self::assertSame([
                'region' => ['The selected region is invalid.', 'The region is unavailable.'],
                'repository' => ['The repository could not be found.'],
            ], $exception->fieldErrors);
            self::assertSame('POST', $exception->method);
            self::assertSame('/applications', $exception->path);
            self::assertSame(422, $exception->statusCode);
            self::assertSame('validation-request-1', $exception->requestId);
        }
    }

    public function testMalformedValidationPayloadFallsBackToTheGenericMessage(): void
    {
        try {
            $this->client([new MockResponse(
                '{"message":"unsafe detail","errors":{"region":"not-a-list"}}',
                ['http_code' => 422],
            )])->createApplication(
                new CreateApplicationRequest('api', 'acme/api', 'eu-central-1', SourceProvider::GITHUB),
            );
            self::fail('Expected validation failure.');
        } catch (CloudValidationException $exception) {
            self::assertSame('Laravel Cloud rejected the request.', $exception->getMessage());
            self::assertNull($exception->apiMessage);
            self::assertSame([], $exception->fieldErrors);
        }
    }

    public function testVariableValidationDetailsRedactTokenValuesAndEchoedRequestPayload(): void
    {
        $token = 'validation-api-token';
        $secret = 'environment-variable-secret';
        $requestPayload = '{"method":"set","variables":[{"key":"APP_KEY","value":"' . $secret . '"}]}';
        $body = json_encode([
            'message' => 'Rejected ' . $requestPayload . ' using ' . $token,
            'errors' => [
                'variables.0.value' => ['Invalid value ' . $secret . ' for token ' . $token],
            ],
        ], JSON_THROW_ON_ERROR);

        try {
            $this->client([new MockResponse($body, ['http_code' => 422])], $token)
                ->setEnvironmentVariables('env-1', new SetEnvironmentVariablesRequest(
                    new EnvironmentVariableInput('APP_KEY', $secret),
                ));
            self::fail('Expected validation failure.');
        } catch (CloudValidationException $exception) {
            $renderable = serialize([$exception->getMessage(), $exception->apiMessage, $exception->fieldErrors]);
            self::assertStringNotContainsString($token, $renderable);
            self::assertStringNotContainsString($secret, $renderable);
            self::assertStringNotContainsString($requestPayload, $renderable);
            self::assertStringContainsString('[redacted]', $renderable);
        }
    }

    public function testPostTransportFailureIsNeverAutomaticallyRetried(): void
    {
        $attempts = 0;
        $http = new MockHttpClient(static function () use (&$attempts): never {
            ++$attempts;
            throw new TransportException('timeout after send');
        });
        $client = new SymfonyLaravelCloudClient($http, new CloudApiToken('secret-token'));

        try {
            $client->createApplication(new CreateApplicationRequest('api', 'acme/api', 'eu-central-1', SourceProvider::GITHUB));
            self::fail('Expected uncertain transport failure.');
        } catch (CloudTransportException $exception) {
            self::assertSame(1, $attempts);
            self::assertStringContainsString('uncertain', $exception->getMessage());
            self::assertStringNotContainsString('secret-token', $exception->getMessage());
        }
    }

    public function testEnvironmentVariablesUseOneOfficialBatchPayloadAndAccept200(): void
    {
        $response = new MockResponse('{"data":{}}', ['http_code' => 200]);

        $request = new SetEnvironmentVariablesRequest(
            new EnvironmentVariableInput('APP_ENV', 'production'),
            new EnvironmentVariableInput('APP_KEY', 'sensitive-value'),
        );
        self::assertSame(EnvironmentVariableMutationMethod::SET, $request->method);

        $this->client([$response])->setEnvironmentVariables('env-123', $request);

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame('https://cloud.laravel.com/api/environments/env-123/variables', $response->getRequestUrl());
        $body = $response->getRequestOptions()['body'];
        self::assertIsString($body);
        self::assertJsonStringEqualsJsonString(
            '{"method":"set","variables":[{"key":"APP_ENV","value":"production"},{"key":"APP_KEY","value":"sensitive-value"}]}',
            $body,
        );
    }

    /** @return iterable<string, array{int, class-string<CloudApiException>}> */
    public static function variableMutationErrorProvider(): iterable
    {
        yield 'forbidden' => [403, CloudAuthenticationException::class];
        yield 'not found' => [404, CloudResourceNotFoundException::class];
        yield 'validation' => [422, CloudValidationException::class];
    }

    /** @param class-string<CloudApiException> $expected */
    #[DataProvider('variableMutationErrorProvider')]
    public function testEnvironmentVariableMutationErrorsMapSafely(int $status, string $expected): void
    {
        $secret = 'never-expose-this-variable-value';

        try {
            $this->client([new MockResponse('{"message":"failed"}', ['http_code' => $status])])
                ->setEnvironmentVariables('env-1', new SetEnvironmentVariablesRequest(
                    new EnvironmentVariableInput('APP_KEY', $secret),
                ));
            self::fail('Expected variable mutation failure.');
        } catch (CloudApiException $exception) {
            self::assertInstanceOf($expected, $exception);
            self::assertSame('POST', $exception->method);
            self::assertSame('/environments/env-1/variables', $exception->path);
            self::assertStringNotContainsString($secret, $exception->getMessage());
        }
    }

    public function testEnvironmentVariablePostTransportFailureIsNotRetriedAndIsRedacted(): void
    {
        $attempts = 0;
        $secret = 'transport-variable-secret';
        $http = new MockHttpClient(static function () use (&$attempts): never {
            ++$attempts;
            throw new TransportException('timeout after send');
        });

        try {
            (new SymfonyLaravelCloudClient($http, new CloudApiToken('token')))->setEnvironmentVariables(
                'env-1',
                new SetEnvironmentVariablesRequest(new EnvironmentVariableInput('APP_KEY', $secret)),
            );
            self::fail('Expected uncertain variable mutation failure.');
        } catch (CloudTransportException $exception) {
            self::assertSame(1, $attempts);
            self::assertStringContainsString('uncertain', $exception->getMessage());
            self::assertStringNotContainsString($secret, $exception->getMessage());
        }
    }

    /** @return iterable<string, array{int, class-string<CloudApiException>}> */
    public static function errorStatusProvider(): iterable
    {
        yield 'unauthenticated' => [401, CloudAuthenticationException::class];
        yield 'forbidden' => [403, CloudAuthenticationException::class];
        yield 'not found' => [404, CloudResourceNotFoundException::class];
        yield 'rate limited' => [429, CloudRateLimitException::class];
        yield 'server error' => [500, CloudApiException::class];
    }

    /** @param class-string<CloudApiException> $expected */
    #[DataProvider('errorStatusProvider')]
    public function testHttpErrorsMapToSafeProjectOwnedExceptions(int $status, string $expected): void
    {
        $token = 'do-not-leak-this-token';

        try {
            $this->client([new MockResponse('{"message":"failed"}', [
                'http_code' => $status,
                'response_headers' => ['X-Request-Id: request-123'],
            ])], $token)->organization();
            self::fail('Expected the API request to fail.');
        } catch (CloudApiException $exception) {
            self::assertInstanceOf($expected, $exception);
            self::assertSame('GET', $exception->method);
            self::assertSame('/meta/organization', $exception->path);
            self::assertSame($status, $exception->statusCode);
            self::assertSame('request-123', $exception->requestId);
            self::assertStringNotContainsString($token, $exception->getMessage());
        }
    }

    public function testTransportFailuresMapToAProjectOwnedExceptionWithoutTheToken(): void
    {
        $token = 'transport-secret';
        $http = new MockHttpClient(static function (): never {
            throw new TransportException('network failure');
        });

        try {
            (new SymfonyLaravelCloudClient($http, new CloudApiToken($token)))->organization();
            self::fail('Expected transport failure.');
        } catch (CloudTransportException $exception) {
            self::assertStringNotContainsString($token, $exception->getMessage());
            self::assertSame('/meta/organization', $exception->path);
        }
    }

    public function testEnvironmentDeleteTargetsExactEncodedIdWithNoBodyAndAcceptsOnly204(): void
    {
        $response = new MockResponse('', ['http_code' => 204]);
        $request = new RecordedRequest();
        $http = new MockHttpClient(function (string $method, string $url) use ($request, $response): MockResponse {
            $request->method = $method;
            $request->url = $url;

            return $response;
        });

        (new SymfonyLaravelCloudClient($http, new CloudApiToken('secret-token')))
            ->deleteEnvironment('env/exact');

        self::assertSame('DELETE', $request->method);
        self::assertSame('https://cloud.laravel.com/api/environments/env%2Fexact', $request->url);
        self::assertArrayNotHasKey('body', $response->getRequestOptions());
    }

    public function testEnvironmentDeleteRejectsUnexpectedSuccessfulStatus(): void
    {
        $this->expectException(CloudResponseException::class);
        $this->client([new MockResponse('{}', ['http_code' => 200])])->deleteEnvironment('env-1');
    }

    /** @return iterable<string, array{int, class-string<CloudApiException>}> */
    public static function environmentDeleteErrorProvider(): iterable
    {
        yield 'forbidden' => [403, CloudAuthenticationException::class];
        yield 'not found' => [404, CloudResourceNotFoundException::class];
        yield 'validation failure' => [422, CloudValidationException::class];
    }

    /** @param class-string<CloudApiException> $expected */
    #[DataProvider('environmentDeleteErrorProvider')]
    public function testEnvironmentDeleteMapsDocumentedErrorsSafely(int $status, string $expected): void
    {
        try {
            $this->client([new MockResponse('{"message":"safe failure"}', ['http_code' => $status])])
                ->deleteEnvironment('env-1');
            self::fail('Expected DELETE failure.');
        } catch (CloudApiException $exception) {
            self::assertInstanceOf($expected, $exception);
            self::assertSame('DELETE', $exception->method);
            self::assertSame('/environments/env-1', $exception->path);
        }
    }

    public function testEnvironmentDeleteTransportFailureIsReportedAsUncertainAndNeverRetried(): void
    {
        $calls = 0;
        $http = new MockHttpClient(static function () use (&$calls): never {
            ++$calls;
            throw new TransportException('connection reset');
        });

        try {
            (new SymfonyLaravelCloudClient($http, new CloudApiToken('secret-token')))->deleteEnvironment('env-1');
            self::fail('Expected uncertain transport failure.');
        } catch (CloudTransportException $exception) {
            self::assertSame('DELETE', $exception->method);
            self::assertStringContainsString('uncertain', $exception->getMessage());
        }

        self::assertSame(1, $calls);
    }

    /** @param list<MockResponse> $responses */
    private function client(array $responses, string $token = 'test-token'): SymfonyLaravelCloudClient
    {
        return new SymfonyLaravelCloudClient(new MockHttpClient($responses), new CloudApiToken($token));
    }

    private static function fixture(string $name): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . '/Fixture/Cloud/' . $name);
        self::assertNotFalse($contents);

        return $contents;
    }

    private static function applicationCreateResponse(): string
    {
        return '{"data":{"id":"app-created","type":"applications","attributes":{"name":"my-api","slug":"my-api","region":"eu-central-1","repository":{"full_name":"acme/my-api","default_branch":"main"}}}}';
    }
}

final class RecordedRequest
{
    public ?string $method = null;
    public ?string $url = null;
}
