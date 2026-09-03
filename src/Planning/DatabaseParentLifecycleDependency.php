<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Planning;

use LaravelCloudBlueprint\State\StateOwnershipClassification;
use LaravelCloudBlueprint\State\StateProvenance;

final readonly class DatabaseParentLifecycleDependency
{
    public function __construct(
        public ResourceAddress $address,
        public StateOwnershipClassification $classification,
        public StateProvenance $provenance,
        public DatabaseDestructiveRole $destructiveRole,
        public string $plannedLifecycleEffect = 'delete_during_guarded_parent_lifecycle',
    ) {
        if ($classification !== StateOwnershipClassification::DERIVED) {
            throw new \InvalidArgumentException('A parent lifecycle dependency requires authoritative derived Cluster-create provenance.');
        }
    }
}
