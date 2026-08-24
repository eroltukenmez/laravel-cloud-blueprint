<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Blueprint;

use InvalidArgumentException;
use LaravelCloudBlueprint\Blueprint\EnvironmentDefinition;
use LaravelCloudBlueprint\Blueprint\EnvironmentDefinitionCollection;
use LaravelCloudBlueprint\Blueprint\VariableDefinitionCollection;
use PHPUnit\Framework\TestCase;

final class EnvironmentDefinitionCollectionTest extends TestCase
{
    public function testItRejectsDuplicateEnvironmentNames(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An environment named "production" already exists.');

        new EnvironmentDefinitionCollection(
            new EnvironmentDefinition('production', 'main', new VariableDefinitionCollection()),
            new EnvironmentDefinition('production', 'release', new VariableDefinitionCollection()),
        );
    }
}
