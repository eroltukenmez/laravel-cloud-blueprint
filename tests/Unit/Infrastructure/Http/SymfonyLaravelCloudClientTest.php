<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Infrastructure\Http;

use LaravelCloudBlueprint\Cloud\CloudApiToken;
use LaravelCloudBlueprint\Blueprint\SourceProvider;
use LaravelCloudBlueprint\Cloud\DTO\CreateApplicationRequest;
use LaravelCloudBlueprint\Cloud\DTO\CreateEnvironmentRequest;
use LaravelCloudBlueprint\Cloud\DTO\EnvironmentVariableInput;
use LaravelCloudBlueprint\Cloud\DTO\SetEnvironmentVariablesRequest;
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
        self::assertSame('https://cloud.laravel.com/api/environments/env-1', $response->getRequestUrl());
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
            self::assertSame('/environments/env-1', $exception->path);
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

    public function testCreateValidationFailureIsControlled(): void
    {
        $this->expectException(CloudValidationException::class);

        $this->client([new MockResponse('{"message":"invalid","errors":{}}', ['http_code' => 422])])
            ->createApplication(new CreateApplicationRequest('api', 'acme/api', 'eu-central-1', SourceProvider::GITHUB));
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

        $this->client([$response])->setEnvironmentVariables('env-123', new SetEnvironmentVariablesRequest(
            new EnvironmentVariableInput('APP_ENV', 'production'),
            new EnvironmentVariableInput('APP_KEY', 'sensitive-value'),
        ));

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame('https://cloud.laravel.com/api/environments/env-123/variables', $response->getRequestUrl());
        $body = $response->getRequestOptions()['body'];
        self::assertIsString($body);
        self::assertJsonStringEqualsJsonString(
            '{"variables":[{"key":"APP_ENV","value":"production"},{"key":"APP_KEY","value":"sensitive-value"}]}',
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
