<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\DTO;

use InvalidArgumentException;

final readonly class SetEnvironmentVariablesRequest
{
    /** @var list<EnvironmentVariableInput> */
    private array $variables;

    public function __construct(EnvironmentVariableInput ...$variables)
    {
        if ($variables === []) {
            throw new InvalidArgumentException('An environment variable request must not be empty.');
        }

        $seen = [];
        foreach ($variables as $variable) {
            if (array_key_exists($variable->key, $seen)) {
                throw new InvalidArgumentException('Environment variable request keys must be unique.');
            }
            $seen[$variable->key] = true;
        }

        $this->variables = array_values($variables);
    }

    /** @return list<EnvironmentVariableInput> */
    public function variables(): array
    {
        return $this->variables;
    }
}
