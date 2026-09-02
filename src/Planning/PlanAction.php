<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Planning;

use LaravelCloudBlueprint\Cloud\DTO\EnvironmentDependencies;
use LaravelCloudBlueprint\Cloud\DTO\DatabaseDependencies;

final readonly class PlanAction
{
    /** @var list<PlanChange> */
    public array $changes;
    public ?ResourceAddress $parent;
    public ?EnvironmentDependencies $environmentDependencies;
    public ?DatabaseDependencies $databaseDependencies;

    public function __construct(
        public ResourceAddress $address,
        public ResourceType $resourceType,
        public PlanOperation $operation,
        public string $reason,
        public ?string $remoteId = null,
        PlanChange|ResourceAddress|EnvironmentDependencies|DatabaseDependencies ...$details,
    ) {
        $parent = null;
        $changes = [];
        $environmentDependencies = null;
        $databaseDependencies = null;
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
            } else {
                $changes[] = $detail;
            }
        }
        $this->parent = $parent;
        $this->environmentDependencies = $environmentDependencies;
        $this->databaseDependencies = $databaseDependencies;
        $this->changes = $changes;
    }

    public function destructiveDependencies(): EnvironmentDependencies|DatabaseDependencies|null
    {
        return $this->environmentDependencies ?? $this->databaseDependencies;
    }
}
