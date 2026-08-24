<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Infrastructure\Http;

use LaravelCloudBlueprint\Cloud\CloudApiToken;
use LaravelCloudBlueprint\Cloud\Exception\CloudApiException;
use LaravelCloudBlueprint\Cloud\Exception\CloudAuthenticationException;
use LaravelCloudBlueprint\Cloud\Exception\CloudRateLimitException;
use LaravelCloudBlueprint\Cloud\Exception\CloudResourceNotFoundException;
use LaravelCloudBlueprint\Cloud\Exception\CloudResponseException;
use LaravelCloudBlueprint\Cloud\Exception\CloudTransportException;
use LaravelCloudBlueprint\Infrastructure\Http\SymfonyLaravelCloudClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SymfonyLaravelCloudClientTest extends TestCase
{
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
        self::assertContains('User-Agent: Laravel-Cloud-Blueprint/0.1.0-alpha.1', $headers['user-agent']);
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
}

final class RecordedRequest
{
    public ?string $method = null;
    public ?string $url = null;
}
