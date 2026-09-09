<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Planning;

use Countable;
use IteratorAggregate;
use LaravelCloudBlueprint\Observation\OwnershipStatus;
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
        foreach ($this->actions as $action) {
            if ($action->reconciliation === PlanReconciliationStatus::SUPPORTED) {
                return true;
            }
        }

        return false;
    }

    public function hasReportableActions(): bool
    {
        foreach ($this->actions as $action) {
            if ($action->operation !== PlanOperation::NO_CHANGE
                || in_array($action->ownership, [OwnershipStatus::DERIVED, OwnershipStatus::UNMANAGED], true)) {
                return true;
            }
        }

        return false;
    }

    public function getIterator(): Traversable
    {
        yield from $this->actions;
    }
}
