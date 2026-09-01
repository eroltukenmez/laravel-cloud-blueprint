<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Planning;

final readonly class PlanAction
{
    /** @var list<PlanChange> */
    public array $changes;
    public ?ResourceAddress $parent;

    public function __construct(
        public ResourceAddress $address,
        public ResourceType $resourceType,
        public PlanOperation $operation,
        public string $reason,
        public ?string $remoteId = null,
        PlanChange|ResourceAddress ...$details,
    ) {
        $parent = null;
        $changes = [];
        foreach ($details as $detail) {
            if ($detail instanceof ResourceAddress) {
                if ($parent !== null) {
                    throw new \InvalidArgumentException('A plan action must not have multiple parents.');
                }
                $parent = $detail;
            } else {
                $changes[] = $detail;
            }
        }
        $this->parent = $parent;
        $this->changes = $changes;
    }
}
