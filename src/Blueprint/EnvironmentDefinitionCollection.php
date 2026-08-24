<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Blueprint;

use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use OutOfBoundsException;
use Traversable;

/**
 * @implements IteratorAggregate<string, EnvironmentDefinition>
 */
final readonly class EnvironmentDefinitionCollection implements Countable, IteratorAggregate
{
    /** @var array<string, EnvironmentDefinition> */
    private array $environments;

    public function __construct(EnvironmentDefinition ...$environments)
    {
        $environmentsByName = [];

        foreach ($environments as $environment) {
            if (isset($environmentsByName[$environment->name])) {
                throw new InvalidArgumentException(sprintf(
                    'An environment named "%s" already exists.',
                    $environment->name,
                ));
            }

            $environmentsByName[$environment->name] = $environment;
        }

        $this->environments = $environmentsByName;
    }

    public function count(): int
    {
        return count($this->environments);
    }

    public function get(string $name): EnvironmentDefinition
    {
        return $this->environments[$name]
            ?? throw new OutOfBoundsException(sprintf('Environment "%s" does not exist.', $name));
    }

    public function getIterator(): Traversable
    {
        yield from $this->environments;
    }
}
