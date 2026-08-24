<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Blueprint\Normalization;

use LaravelCloudBlueprint\Blueprint\BlueprintSchemaVersion;
use LaravelCloudBlueprint\Blueprint\EnvironmentVariableReference;
use LaravelCloudBlueprint\Blueprint\LiteralVariableValue;
use LaravelCloudBlueprint\Blueprint\Normalization\BlueprintNormalizationException;
use LaravelCloudBlueprint\Blueprint\Normalization\BlueprintNormalizer;
use LaravelCloudBlueprint\Blueprint\SourceProvider;
use LaravelCloudBlueprint\Infrastructure\Yaml\SymfonyYamlDecoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlueprintNormalizerTest extends TestCase
{
    public function testItDecodesAndNormalizesACompleteBlueprint(): void
    {
        $yaml = <<<'YAML'
version: 1
organization: my-organization
application:
  name: my-api
  source:
    provider: github
    repository: acme/my-api
environments:
  production:
    branch: main
    variables:
      APP_ENV:
        value: production
      APP_DEBUG:
        value: "false"
      APP_KEY:
        from_env: APP_KEY
        sensitive: true
  staging:
    branch: develop
    variables: {}
YAML;

        $blueprint = (new BlueprintNormalizer())->normalize((new SymfonyYamlDecoder())->decode($yaml));

        self::assertSame(BlueprintSchemaVersion::V1, $blueprint->schemaVersion);
        self::assertSame('my-organization', $blueprint->organization);
        self::assertSame('my-api', $blueprint->application->name);
        self::assertSame(SourceProvider::GITHUB, $blueprint->application->source->provider);
        self::assertSame('acme/my-api', $blueprint->application->source->repository);
        self::assertCount(2, $blueprint->environments);
        self::assertSame('production', $blueprint->environments->get('production')->name);
        self::assertSame('staging', $blueprint->environments->get('staging')->name);

        $variables = $blueprint->environments->get('production')->variables;
        $literal = $variables->get('APP_ENV');
        $debug = $variables->get('APP_DEBUG');
        $reference = $variables->get('APP_KEY');

        self::assertInstanceOf(LiteralVariableValue::class, $literal->valueSource);
        self::assertSame('production', $literal->valueSource->value);
        self::assertFalse($literal->sensitive);
        self::assertInstanceOf(LiteralVariableValue::class, $debug->valueSource);
        self::assertSame('false', $debug->valueSource->value);
        self::assertInstanceOf(EnvironmentVariableReference::class, $reference->valueSource);
        self::assertSame('APP_KEY', $reference->valueSource->name);
        self::assertTrue($reference->sensitive);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidBlueprintProvider(): iterable
    {
        yield 'unknown provider' => [
            self::validData(provider: 'gitlab'),
            'application.source.provider',
        ];
        yield 'unsupported version' => [
            self::validData(version: 2),
            'version',
        ];
        yield 'both variable sources' => [
            self::validData(variable: ['value' => 'literal', 'from_env' => 'HOST_VALUE']),
            'environments.production.variables.EXAMPLE',
        ];
        yield 'neither variable source' => [
            self::validData(variable: ['sensitive' => true]),
            'environments.production.variables.EXAMPLE',
        ];
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('invalidBlueprintProvider')]
    public function testMalformedNormalizedDataFailsAtItsMachineReadablePath(
        array $data,
        string $expectedPath,
    ): void {
        try {
            (new BlueprintNormalizer())->normalize($data);
            self::fail('Expected blueprint normalization to fail.');
        } catch (BlueprintNormalizationException $exception) {
            self::assertSame($expectedPath, $exception->path);
        }
    }

    /**
     * @param array<string, mixed> $variable
     * @return array<string, mixed>
     */
    private static function validData(
        int $version = 1,
        string $provider = 'github',
        array $variable = ['value' => 'literal'],
    ): array {
        return [
            'version' => $version,
            'organization' => 'acme',
            'application' => [
                'name' => 'example',
                'source' => [
                    'provider' => $provider,
                    'repository' => 'acme/example',
                ],
            ],
            'environments' => [
                'production' => [
                    'branch' => 'main',
                    'variables' => ['EXAMPLE' => $variable],
                ],
            ],
        ];
    }
}
