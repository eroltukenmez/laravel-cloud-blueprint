<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Blueprint\Validation;

use Countable;
use IteratorAggregate;
use Traversable;

/** @implements IteratorAggregate<int, ValidationError> */
final readonly class ValidationResult implements Countable, IteratorAggregate
{
    /** @var list<ValidationError> */
    private array $errors;

    public function __construct(ValidationError ...$errors)
    {
        $this->errors = array_values($errors);
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    public function count(): int
    {
        return count($this->errors);
    }

    public function getIterator(): Traversable
    {
        yield from $this->errors;
    }
}
