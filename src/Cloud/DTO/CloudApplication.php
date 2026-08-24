<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\DTO;

final readonly class CloudApplication
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $slug,
        public string $region,
        public ?string $repository,
    ) {
    }
}
