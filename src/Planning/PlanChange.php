<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Planning;

final readonly class PlanChange
{
    public function __construct(
        public string $field,
        public string $before,
        public string $after,
    ) {
    }
}
