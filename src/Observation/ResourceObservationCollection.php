<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Observation;

use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use LaravelCloudBlueprint\Planning\ResourceType;
use Traversable;

/** @implements IteratorAggregate<int, ResourceObservation> */
final readonly class ResourceObservationCollection implements Countable, IteratorAggregate
{
    /** @var list<ResourceObservation> */
    private array $observations;

    public function __construct(ResourceObservation ...$observations)
    {
        $indexed = [];
        foreach ($observations as $observation) {
            $identity = (string) $observation->address;
            if (isset($indexed[$identity])) {
                throw new InvalidArgumentException('Resource observations must have unique addresses.');
            }
            $indexed[$identity] = $observation;
        }

        $rank = array_flip(array_map(
            static fn (ResourceType $type): string => $type->value,
            ResourceType::cases(),
        ));
        $normalized = array_values($indexed);
        usort($normalized, static fn (ResourceObservation $left, ResourceObservation $right): int =>
            $rank[$left->resourceType->value] <=> $rank[$right->resourceType->value]
                ?: strcmp((string) $left->address, (string) $right->address));
        $this->observations = $normalized;
    }

    public function count(): int
    {
        return count($this->observations);
    }

    public function getIterator(): Traversable
    {
        yield from $this->observations;
    }
}
