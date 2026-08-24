<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\DTO;

final readonly class CloudOrganization
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
    ) {
    }
}
