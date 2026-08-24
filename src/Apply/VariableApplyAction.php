<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Apply;

use LaravelCloudBlueprint\Blueprint\VariableDefinition;
use LaravelCloudBlueprint\Planning\PlanAction;

final readonly class VariableApplyAction
{
    public function __construct(
        public string $environmentName,
        public VariableDefinition $definition,
        public PlanAction $action,
    ) {
    }
}
