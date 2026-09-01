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
        $deletes = [];
        $other = [];
        foreach ($actions as $action) {
            if ($action->operation === PlanOperation::DELETE) {
                $deletes[] = $action;
            } else {
                $other[] = $action;
            }
        }

        $deleteAddresses = [];
        foreach ($deletes as $delete) {
            $deleteAddresses[(string) $delete->address] = $delete;
        }
        $depth = static function (PlanAction $action) use ($deleteAddresses): int {
            $depth = 0;
            $parent = $action->parent;
            $seen = [];
            while ($parent !== null && isset($deleteAddresses[(string) $parent])) {
                if (isset($seen[(string) $parent])) {
                    break;
                }
                $seen[(string) $parent] = true;
                ++$depth;
                $parent = $deleteAddresses[(string) $parent]->parent;
            }
            return $depth;
        };
        usort($deletes, static fn (PlanAction $left, PlanAction $right): int =>
            $depth($right) <=> $depth($left) ?: strcmp((string) $left->address, (string) $right->address));

        $this->actions = [...$deletes, ...$other];
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
        return $this->countByOperation(PlanOperation::CREATE) > 0
            || $this->countByOperation(PlanOperation::UPDATE) > 0
            || $this->countByOperation(PlanOperation::DELETE) > 0;
    }

    public function getIterator(): Traversable
    {
        yield from $this->actions;
    }
}
