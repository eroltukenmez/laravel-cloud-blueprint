<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Application;

use LaravelCloudBlueprint\Application\BlueprintLoader;
use LaravelCloudBlueprint\Blueprint\BlueprintSchemaVersion;
use LaravelCloudBlueprint\Blueprint\Normalization\BlueprintNormalizer;
use LaravelCloudBlueprint\Blueprint\Validation\BlueprintValidator;
use LaravelCloudBlueprint\Infrastructure\Yaml\SymfonyYamlDecoder;
use PHPUnit\Framework\TestCase;

final class BlueprintLoaderTest extends TestCase
{
    public function testItReturnsABlueprintForValidContent(): void
    {
        $result = $this->loader()->load(<<<'YAML'
version: 1
organization: acme
application:
  name: example
  source:
    provider: github
    repository: acme/example
environments:
  production:
    branch: main
YAML);

        self::assertTrue($result->isValid());
        self::assertSame(BlueprintSchemaVersion::V1, $result->blueprint()->schemaVersion);
        self::assertCount(0, $result->blueprint()->environments->get('production')->variables);
    }

    public function testItDoesNotNormalizeInvalidContent(): void
    {
        $result = $this->loader()->load(<<<'YAML'
version: 2
organization: ''
application: invalid
environments: []
YAML);

        self::assertFalse($result->isValid());
        self::assertCount(3, $result->validation);
    }

    private function loader(): BlueprintLoader
    {
        return new BlueprintLoader(
            new SymfonyYamlDecoder(),
            new BlueprintValidator(),
            new BlueprintNormalizer(),
        );
    }
}
