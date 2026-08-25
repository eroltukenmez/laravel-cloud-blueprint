<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Planning;

final readonly class PlanAction
{
    /** @var list<PlanChange> */
    public array $changes;

    public function __construct(
        public ResourceAddress $address,
        public ResourceType $resourceType,
        public PlanOperation $operation,
        public string $reason,
        public ?string $remoteId = null,
        PlanChange ...$changes,
    ) {
        $this->changes = array_values($changes);
    }
}
