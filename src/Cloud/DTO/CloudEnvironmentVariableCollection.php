<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\DTO;

use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

/** @implements IteratorAggregate<string, CloudEnvironmentVariable> */
final readonly class CloudEnvironmentVariableCollection implements Countable, IteratorAggregate
{
    /** @var array<string, CloudEnvironmentVariable> */
    private array $variables;

    public function __construct(CloudEnvironmentVariable ...$variables)
    {
        $indexed = [];
        foreach ($variables as $variable) {
            if (array_key_exists($variable->key, $indexed)) {
                throw new InvalidArgumentException('Remote environment variable keys must be unique.');
            }
            $indexed[$variable->key] = $variable;
        }
        $this->variables = $indexed;
    }

    public function find(string $key): ?CloudEnvironmentVariable
    {
        return $this->variables[$key] ?? null;
    }

    public function count(): int
    {
        return count($this->variables);
    }

    public function getIterator(): Traversable
    {
        yield from $this->variables;
    }
}
