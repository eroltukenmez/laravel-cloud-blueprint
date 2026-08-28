<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Blueprint;

use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use OutOfBoundsException;
use Traversable;

/** @implements IteratorAggregate<string, LogicalDatabaseDefinition> */
final readonly class LogicalDatabaseDefinitionCollection implements Countable, IteratorAggregate
{
    /** @var array<string, LogicalDatabaseDefinition> */
    private array $databases;

    public function __construct(LogicalDatabaseDefinition ...$databases)
    {
        $indexed = [];
        foreach ($databases as $database) {
            if (isset($indexed[$database->name])) {
                throw new InvalidArgumentException(sprintf('A logical Database named "%s" already exists.', $database->name));
            }
            $indexed[$database->name] = $database;
        }
        ksort($indexed, SORT_STRING);
        $this->databases = $indexed;
    }

    public function count(): int
    {
        return count($this->databases);
    }

    public function get(string $name): LogicalDatabaseDefinition
    {
        return $this->databases[$name]
            ?? throw new OutOfBoundsException(sprintf('Logical Database "%s" does not exist.', $name));
    }

    public function getIterator(): Traversable
    {
        yield from $this->databases;
    }
}
