<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Blueprint;

use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use OutOfBoundsException;
use Traversable;

/**
 * @implements IteratorAggregate<string, VariableDefinition>
 */
final readonly class VariableDefinitionCollection implements Countable, IteratorAggregate
{
    /** @var array<string, VariableDefinition> */
    private array $variables;

    public function __construct(VariableDefinition ...$variables)
    {
        $variablesByName = [];

        foreach ($variables as $variable) {
            if (isset($variablesByName[$variable->name])) {
                throw new InvalidArgumentException(sprintf(
                    'A variable named "%s" already exists.',
                    $variable->name,
                ));
            }

            $variablesByName[$variable->name] = $variable;
        }

        $this->variables = $variablesByName;
    }

    public function count(): int
    {
        return count($this->variables);
    }

    public function get(string $name): VariableDefinition
    {
        return $this->variables[$name]
            ?? throw new OutOfBoundsException(sprintf('Variable "%s" does not exist.', $name));
    }

    public function getIterator(): Traversable
    {
        yield from $this->variables;
    }
}
