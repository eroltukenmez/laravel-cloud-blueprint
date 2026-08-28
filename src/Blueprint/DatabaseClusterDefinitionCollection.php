<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Blueprint;

use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use OutOfBoundsException;
use Traversable;

/** @implements IteratorAggregate<string, DatabaseClusterDefinition> */
final readonly class DatabaseClusterDefinitionCollection implements Countable, IteratorAggregate
{
    /** @var array<string, DatabaseClusterDefinition> */
    private array $clusters;

    public function __construct(DatabaseClusterDefinition ...$clusters)
    {
        $indexed = [];
        foreach ($clusters as $cluster) {
            if (isset($indexed[$cluster->name])) {
                throw new InvalidArgumentException(sprintf('A Database Cluster named "%s" already exists.', $cluster->name));
            }
            $indexed[$cluster->name] = $cluster;
        }
        ksort($indexed, SORT_STRING);
        $this->clusters = $indexed;
    }

    public function count(): int
    {
        return count($this->clusters);
    }

    public function get(string $name): DatabaseClusterDefinition
    {
        return $this->clusters[$name]
            ?? throw new OutOfBoundsException(sprintf('Database Cluster "%s" does not exist.', $name));
    }

    public function getIterator(): Traversable
    {
        yield from $this->clusters;
    }
}
