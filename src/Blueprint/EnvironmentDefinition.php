<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Blueprint;

final readonly class EnvironmentDefinition
{
    public function __construct(
        public string $name,
        public string $branch,
        public VariableDefinitionCollection $variables,
        public ?DatabaseReference $database = null,
    ) {
    }
}
