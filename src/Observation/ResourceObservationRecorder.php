<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Observation;

use LaravelCloudBlueprint\Planning\ResourceAddress;

final class ResourceObservationRecorder
{
    /** @var array<string, ResourceObservation> */
    private array $observations = [];
    private bool $reporting = false;

    public function reset(bool $reporting = false): void
    {
        $this->observations = [];
        $this->reporting = $reporting;
    }

    public function isReporting(): bool
    {
        return $this->reporting;
    }

    public function record(ResourceObservation $observation): ResourceObservation
    {
        $this->observations[(string) $observation->address] = $observation;

        return $observation;
    }

    public function collection(): ResourceObservationCollection
    {
        return new ResourceObservationCollection(...array_values($this->observations));
    }

    public function find(ResourceAddress $address): ?ResourceObservation
    {
        return $this->observations[(string) $address] ?? null;
    }
}
