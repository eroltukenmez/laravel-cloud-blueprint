<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Apply;

use Countable;
use IteratorAggregate;
use Traversable;

/** @implements IteratorAggregate<int, ApplyResourceOutcome> */
final readonly class ApplyResult implements Countable, IteratorAggregate
{
    /** @var list<ApplyResourceOutcome> */
    private array $outcomes;

    public function __construct(
        public ApplyStatus $status,
        ApplyResourceOutcome ...$outcomes,
    ) {
        $this->outcomes = array_values($outcomes);
    }

    public function createdCount(): int
    {
        return $this->countOperation(ApplyOutcomeOperation::CREATED);
    }

    public function unchangedCount(): int
    {
        return $this->countOperation(ApplyOutcomeOperation::UNCHANGED);
    }

    public function updatedCount(): int
    {
        return $this->countOperation(ApplyOutcomeOperation::UPDATED);
    }

    public function deletedCount(): int
    {
        return $this->countOperation(ApplyOutcomeOperation::DELETED);
    }

    public function failedCount(): int
    {
        return $this->countOperation(ApplyOutcomeOperation::FAILED);
    }

    public function hasDestructiveOutcomes(): bool
    {
        foreach ($this->outcomes as $outcome) {
            if ($outcome->deleted !== null
                || $outcome->confirmed !== null
                || $outcome->stateCheckpointed !== null) {
                return true;
            }
        }

        return false;
    }

    public function count(): int
    {
        return count($this->outcomes);
    }

    public function getIterator(): Traversable
    {
        yield from $this->outcomes;
    }

    private function countOperation(ApplyOutcomeOperation $operation): int
    {
        return count(array_filter(
            $this->outcomes,
            static fn (ApplyResourceOutcome $outcome): bool => $outcome->operation === $operation,
        ));
    }
}
