<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Drift;

use LaravelCloudBlueprint\Blueprint\Blueprint;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudClient;
use LaravelCloudBlueprint\Planning\CreatePlan;
use LaravelCloudBlueprint\State\StateDocument;

final readonly class CreateDriftReport
{
    public function __construct(private CreatePlan $observations)
    {
    }

    public function create(Blueprint $blueprint, LaravelCloudClient $cloud, StateDocument $state): DriftReport
    {
        return new DriftReport($this->observations->collectObservations($blueprint, $cloud, $state));
    }
}
