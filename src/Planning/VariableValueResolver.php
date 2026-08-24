<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Planning;

use LaravelCloudBlueprint\Blueprint\EnvironmentVariableReference;
use LaravelCloudBlueprint\Blueprint\LiteralVariableValue;
use LaravelCloudBlueprint\Blueprint\VariableDefinition;
use LaravelCloudBlueprint\Planning\Contract\EnvironmentValueProvider;
use LaravelCloudBlueprint\Planning\Exception\MissingEnvironmentValueException;
use LogicException;

final readonly class VariableValueResolver
{
    public function __construct(private EnvironmentValueProvider $environment)
    {
    }

    public function resolve(VariableDefinition $variable, ResourceAddress $address): string
    {
        if ($variable->valueSource instanceof LiteralVariableValue) {
            return $variable->valueSource->value;
        }

        if ($variable->valueSource instanceof EnvironmentVariableReference) {
            $value = $this->environment->value($variable->valueSource->name);
            if ($value === null) {
                throw new MissingEnvironmentValueException(sprintf(
                    'Unable to resolve the desired value for "%s" from the local environment.',
                    (string) $address,
                ));
            }
            return $value;
        }

        throw new LogicException('Unsupported variable value source.');
    }
}
