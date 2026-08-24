<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Planning;

final readonly class ResourceAddress
{
    public function __construct(
        public ResourceType $type,
        public string $name,
    ) {
    }

    public function __toString(): string
    {
        return $this->type->value . '.' . $this->name;
    }
}
