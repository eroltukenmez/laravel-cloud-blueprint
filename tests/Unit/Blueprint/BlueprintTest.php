<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Blueprint;

use LaravelCloudBlueprint\Blueprint\ApplicationDefinition;
use LaravelCloudBlueprint\Blueprint\Blueprint;
use LaravelCloudBlueprint\Blueprint\BlueprintSchemaVersion;
use LaravelCloudBlueprint\Blueprint\EnvironmentDefinition;
use LaravelCloudBlueprint\Blueprint\EnvironmentDefinitionCollection;
use LaravelCloudBlueprint\Blueprint\EnvironmentVariableReference;
use LaravelCloudBlueprint\Blueprint\LiteralVariableValue;
use LaravelCloudBlueprint\Blueprint\SourceDefinition;
use LaravelCloudBlueprint\Blueprint\SourceProvider;
use LaravelCloudBlueprint\Blueprint\VariableDefinition;
use LaravelCloudBlueprint\Blueprint\VariableDefinitionCollection;
use PHPUnit\Framework\TestCase;

final class BlueprintTest extends TestCase
{
    public function testACompleteBlueprintRetainsItsTypedDefinitions(): void
    {
        $literal = new LiteralVariableValue('production');
        $reference = new EnvironmentVariableReference('DATABASE_URL');
        $productionVariables = new VariableDefinitionCollection(
            new VariableDefinition('APP_ENV', $literal, false),
            new VariableDefinition('DB_URL', $reference, true),
        );
        $environments = new EnvironmentDefinitionCollection(
            new EnvironmentDefinition('production', 'main', $productionVariables),
            new EnvironmentDefinition('staging', 'develop', new VariableDefinitionCollection()),
        );
        $source = new SourceDefinition(SourceProvider::GITHUB, 'acme/example');
        $application = new ApplicationDefinition('Example', $source);

        $blueprint = new Blueprint(
            BlueprintSchemaVersion::V1,
            'acme',
            $application,
            $environments,
        );

        self::assertSame(BlueprintSchemaVersion::V1, $blueprint->schemaVersion);
        self::assertSame('acme', $blueprint->organization);
        self::assertSame('Example', $blueprint->application->name);
        self::assertSame(SourceProvider::GITHUB, $blueprint->application->source->provider);
        self::assertSame('acme/example', $blueprint->application->source->repository);
        self::assertCount(2, $blueprint->environments);
        self::assertSame(['production', 'staging'], array_keys(iterator_to_array($blueprint->environments)));

        $variables = $blueprint->environments->get('production')->variables;

        self::assertCount(2, $variables);
        self::assertSame($literal, $variables->get('APP_ENV')->valueSource);
        self::assertSame('production', $literal->value);
        self::assertFalse($variables->get('APP_ENV')->sensitive);
        self::assertSame($reference, $variables->get('DB_URL')->valueSource);
        self::assertSame('DATABASE_URL', $reference->name);
        self::assertTrue($variables->get('DB_URL')->sensitive);
    }
}
