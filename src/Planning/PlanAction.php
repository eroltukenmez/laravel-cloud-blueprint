<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Planning;

final readonly class PlanAction
{
    public function __construct(
        public ResourceAddress $address,
        public ResourceType $resourceType,
        public PlanOperation $operation,
        public string $reason,
    ) {
    }
}
