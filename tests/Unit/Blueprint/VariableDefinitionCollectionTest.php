<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Blueprint;

use InvalidArgumentException;
use LaravelCloudBlueprint\Blueprint\LiteralVariableValue;
use LaravelCloudBlueprint\Blueprint\VariableDefinition;
use LaravelCloudBlueprint\Blueprint\VariableDefinitionCollection;
use PHPUnit\Framework\TestCase;

final class VariableDefinitionCollectionTest extends TestCase
{
    public function testItRejectsDuplicateVariableNames(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A variable named "APP_ENV" already exists.');

        new VariableDefinitionCollection(
            new VariableDefinition('APP_ENV', new LiteralVariableValue('production'), false),
            new VariableDefinition('APP_ENV', new LiteralVariableValue('staging'), false),
        );
    }
}
