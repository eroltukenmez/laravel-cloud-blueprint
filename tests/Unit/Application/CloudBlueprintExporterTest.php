<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Application;

use LaravelCloudBlueprint\Application\BlueprintLoader;
use LaravelCloudBlueprint\Application\CloudBlueprintExporter;
use LaravelCloudBlueprint\Application\CloudBlueprintExportException;
use LaravelCloudBlueprint\Blueprint\Normalization\BlueprintNormalizer;
use LaravelCloudBlueprint\Blueprint\SourceProvider;
use LaravelCloudBlueprint\Blueprint\Validation\BlueprintValidator;
use LaravelCloudBlueprint\Cloud\DTO\CloudApplication;
use LaravelCloudBlueprint\Cloud\DTO\CloudEnvironment;
use LaravelCloudBlueprint\Cloud\DTO\CloudOrganization;
use LaravelCloudBlueprint\Infrastructure\Yaml\SymfonyBlueprintYamlEncoder;
use LaravelCloudBlueprint\Infrastructure\Yaml\SymfonyYamlDecoder;
use PHPUnit\Framework\TestCase;

final class CloudBlueprintExporterTest extends TestCase
{
    public function testExportsTypedSupportedResourcesAndEncodedYamlValidates(): void
    {
        $blueprint = (new CloudBlueprintExporter())->export(
            new CloudOrganization('secret-org-id', 'Acme', 'acme'),
            new CloudApplication(
                'secret-app-id',
                'my-api',
                'my-api',
                'eu-central-1',
                'acme/my-api',
                SourceProvider::GITLAB,
            ),
            [new CloudEnvironment('secret-env-id', 'secret-app-id', 'production', 'main')],
        );

        self::assertSame('acme', $blueprint->organization);
        self::assertSame('my-api', $blueprint->application->name);
        self::assertSame('eu-central-1', $blueprint->application->region);
        self::assertSame(SourceProvider::GITLAB, $blueprint->application->source->provider);
        self::assertSame('acme/my-api', $blueprint->application->source->repository);
        self::assertSame('main', $blueprint->environments->get('production')->branch);
        self::assertCount(0, $blueprint->environments->get('production')->variables);

        $yaml = (new SymfonyBlueprintYamlEncoder())->encode($blueprint);
        self::assertStringNotContainsString('variables:', $yaml);
        self::assertStringNotContainsString('secret-', $yaml);

        $result = (new BlueprintLoader(
            new SymfonyYamlDecoder(),
            new BlueprintValidator(),
            new BlueprintNormalizer(),
        ))->load($yaml);
        self::assertTrue($result->isValid());
        $roundTrip = $result->blueprint();
        self::assertSame($blueprint->schemaVersion, $roundTrip->schemaVersion);
        self::assertSame($blueprint->organization, $roundTrip->organization);
        self::assertSame($blueprint->application->name, $roundTrip->application->name);
        self::assertSame($blueprint->application->region, $roundTrip->application->region);
        self::assertSame($blueprint->application->source->provider, $roundTrip->application->source->provider);
        self::assertSame($blueprint->application->source->repository, $roundTrip->application->source->repository);
        self::assertSame(
            $blueprint->environments->get('production')->branch,
            $roundTrip->environments->get('production')->branch,
        );
    }

    public function testExplicitProviderIsUsedOnlyWhenRemoteProviderIsUnavailable(): void
    {
        $blueprint = (new CloudBlueprintExporter())->export(
            new CloudOrganization('org', 'Acme', 'acme'),
            new CloudApplication('app', 'API', 'api', 'us-east-1', 'acme/api'),
            [],
            SourceProvider::BITBUCKET,
        );

        self::assertSame(SourceProvider::BITBUCKET, $blueprint->application->source->provider);
    }

    public function testRemoteProviderTakesPrecedenceOverExplicitFallback(): void
    {
        $blueprint = (new CloudBlueprintExporter())->export(
            new CloudOrganization('org', 'Acme', 'acme'),
            new CloudApplication('app', 'API', 'api', 'us-east-1', 'acme/api', SourceProvider::GITHUB),
            [],
            SourceProvider::GITLAB,
        );

        self::assertSame(SourceProvider::GITHUB, $blueprint->application->source->provider);
    }

    public function testMissingProviderFailsInsteadOfGuessing(): void
    {
        $this->expectException(CloudBlueprintExportException::class);
        $this->expectExceptionMessage('source provider');

        (new CloudBlueprintExporter())->export(
            new CloudOrganization('org', 'Acme', 'acme'),
            new CloudApplication('app', 'API', 'api', 'us-east-1', 'acme/api'),
            [],
        );
    }

    public function testMissingOrUnsafeRepositoryFails(): void
    {
        $this->expectException(CloudBlueprintExportException::class);
        $this->expectExceptionMessage('repository');

        (new CloudBlueprintExporter())->export(
            new CloudOrganization('org', 'Acme', 'acme'),
            new CloudApplication('app', 'API', 'api', 'us-east-1', 'https://example.test/acme/api', SourceProvider::GITHUB),
            [],
        );
    }

    public function testMissingEnvironmentBranchFailsInsteadOfGuessing(): void
    {
        $this->expectException(CloudBlueprintExportException::class);
        $this->expectExceptionMessage('production');

        (new CloudBlueprintExporter())->export(
            new CloudOrganization('org', 'Acme', 'acme'),
            new CloudApplication('app', 'API', 'api', 'us-east-1', 'acme/api', SourceProvider::GITHUB),
            [new CloudEnvironment('env', 'app', 'production', null)],
        );
    }
}
