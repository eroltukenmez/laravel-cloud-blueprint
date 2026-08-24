<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Planning;

use Countable;
use IteratorAggregate;
use Traversable;

/** @implements IteratorAggregate<int, PlanAction> */
final readonly class ExecutionPlan implements Countable, IteratorAggregate
{
    /** @var list<PlanAction> */
    private array $actions;

    public function __construct(PlanAction ...$actions)
    {
        $this->actions = array_values($actions);
    }

    public function count(): int
    {
        return count($this->actions);
    }

    public function countByOperation(PlanOperation $operation): int
    {
        return count(array_filter(
            $this->actions,
            static fn (PlanAction $action): bool => $action->operation === $operation,
        ));
    }

    public function hasActionableChanges(): bool
    {
        return $this->countByOperation(PlanOperation::CREATE) > 0;
    }

    public function getIterator(): Traversable
    {
        yield from $this->actions;
    }
}
