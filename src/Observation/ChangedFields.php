<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Observation;

use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

/** @implements IteratorAggregate<int, string> */
final readonly class ChangedFields implements Countable, IteratorAggregate
{
    /** @var list<string> */
    private array $fields;

    public function __construct(string ...$fields)
    {
        $unique = [];
        foreach ($fields as $field) {
            if (preg_match('/^[a-z][a-z0-9_]*$/D', $field) !== 1) {
                throw new InvalidArgumentException('Changed fields must use safe snake_case names.');
            }
            if (isset($unique[$field])) {
                throw new InvalidArgumentException('Changed fields must be unique.');
            }
            $unique[$field] = true;
        }

        $normalized = array_keys($unique);
        sort($normalized, SORT_STRING);
        $this->fields = $normalized;
    }

    public static function none(): self
    {
        return new self();
    }

    /** @return list<string> */
    public function values(): array
    {
        return $this->fields;
    }

    public function count(): int
    {
        return count($this->fields);
    }

    public function getIterator(): Traversable
    {
        yield from $this->fields;
    }
}
