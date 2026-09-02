<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\DTO;

final readonly class CloudDatabase
{
    public function __construct(
        public string $id,
        public string $clusterId,
        public string $name,
        public ?string $relationshipClusterId = null,
        /** @var list<string> */
        public array $environmentIds = [],
        public bool $destructiveRelationshipsComplete = false,
        /** @var list<string> */
        public array $missingRelationships = [],
        /** @var list<string> */
        public array $unknownRelationships = [],
    ) {
    }
}
