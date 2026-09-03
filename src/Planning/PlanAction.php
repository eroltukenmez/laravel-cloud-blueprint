<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Planning;

use LaravelCloudBlueprint\Cloud\DTO\EnvironmentDependencies;
use LaravelCloudBlueprint\Cloud\DTO\DatabaseDependencies;
use LaravelCloudBlueprint\State\StateOwnershipClassification;
use LaravelCloudBlueprint\State\StateProvenance;

final readonly class PlanAction
{
    /** @var list<PlanChange> */
    public array $changes;
    public ?ResourceAddress $parent;
    public ?EnvironmentDependencies $environmentDependencies;
    public ?DatabaseDependencies $databaseDependencies;
    public ?StateOwnershipClassification $ownershipClassification;
    public ?StateProvenance $provenance;
    public ?DatabaseDestructiveRole $destructiveRole;
    public ?DatabaseParentLifecycleDependency $parentLifecycleDependency;

    public function __construct(
        public ResourceAddress $address,
        public ResourceType $resourceType,
        public PlanOperation $operation,
        public string $reason,
        public ?string $remoteId = null,
        PlanChange|ResourceAddress|EnvironmentDependencies|DatabaseDependencies|StateOwnershipClassification|StateProvenance|DatabaseDestructiveRole|DatabaseParentLifecycleDependency ...$details,
    ) {
        $parent = null;
        $changes = [];
        $environmentDependencies = null;
        $databaseDependencies = null;
        $ownershipClassification = null;
        $provenance = null;
        $destructiveRole = null;
        $parentLifecycleDependency = null;
        foreach ($details as $detail) {
            if ($detail instanceof ResourceAddress) {
                if ($parent !== null) {
                    throw new \InvalidArgumentException('A plan action must not have multiple parents.');
                }
                $parent = $detail;
            } elseif ($detail instanceof EnvironmentDependencies) {
                if ($environmentDependencies !== null || $databaseDependencies !== null) {
                    throw new \InvalidArgumentException('A plan action must not have multiple dependency summaries.');
                }
                $environmentDependencies = $detail;
            } elseif ($detail instanceof DatabaseDependencies) {
                if ($databaseDependencies !== null || $environmentDependencies !== null) {
                    throw new \InvalidArgumentException('A plan action must not have multiple dependency summaries.');
                }
                $databaseDependencies = $detail;
            } elseif ($detail instanceof StateOwnershipClassification) {
                if ($ownershipClassification !== null) {
                    throw new \InvalidArgumentException('A plan action must not have multiple ownership classifications.');
                }
                $ownershipClassification = $detail;
            } elseif ($detail instanceof StateProvenance) {
                if ($provenance !== null) {
                    throw new \InvalidArgumentException('A plan action must not have multiple provenance values.');
                }
                $provenance = $detail;
            } elseif ($detail instanceof DatabaseDestructiveRole) {
                if ($destructiveRole !== null) {
                    throw new \InvalidArgumentException('A plan action must not have multiple destructive roles.');
                }
                $destructiveRole = $detail;
            } elseif ($detail instanceof DatabaseParentLifecycleDependency) {
                if ($parentLifecycleDependency !== null) {
                    throw new \InvalidArgumentException('A plan action must not have multiple parent lifecycle dependencies.');
                }
                $parentLifecycleDependency = $detail;
            } else {
                $changes[] = $detail;
            }
        }
        if ($ownershipClassification === StateOwnershipClassification::DERIVED && $provenance === null) {
            throw new \InvalidArgumentException('A derived plan action requires provenance.');
        }
        if ($ownershipClassification !== StateOwnershipClassification::DERIVED && $provenance !== null) {
            throw new \InvalidArgumentException('Plan action provenance requires a derived ownership classification.');
        }
        if ($destructiveRole === DatabaseDestructiveRole::PARENT_LIFECYCLE_DEPENDENCY
            && $ownershipClassification !== StateOwnershipClassification::DERIVED) {
            throw new \InvalidArgumentException('A parent lifecycle dependency requires authoritative derived provenance.');
        }
        $this->parent = $parent;
        $this->environmentDependencies = $environmentDependencies;
        $this->databaseDependencies = $databaseDependencies;
        $this->ownershipClassification = $ownershipClassification;
        $this->provenance = $provenance;
        $this->destructiveRole = $destructiveRole;
        $this->parentLifecycleDependency = $parentLifecycleDependency;
        $this->changes = $changes;
    }

    public function destructiveDependencies(): EnvironmentDependencies|DatabaseDependencies|null
    {
        return $this->environmentDependencies ?? $this->databaseDependencies;
    }
}
