<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Drift\Rendering;

use LaravelCloudBlueprint\Drift\DriftReport;
use LaravelCloudBlueprint\Observation\ResourceObservation;

final readonly class DriftJsonRenderer
{
    /** @return array{status: string, summary: array<string, int>, entries: list<array<string, mixed>>} */
    public function render(DriftReport $report): array
    {
        return [
            'status' => 'success',
            'summary' => $report->summary->toArray(),
            'entries' => array_map(
                self::entry(...),
                iterator_to_array($report->observations, false),
            ),
        ];
    }

    /** @return array<string, mixed> */
    private static function entry(ResourceObservation $observation): array
    {
        $entry = [
            'resource' => (string) $observation->address,
            'type' => $observation->resourceType->value,
            'observation' => $observation->observation->value,
            'ownership' => $observation->ownership->value,
            'reconciliation' => $observation->reconciliation->value,
            'evidence' => $observation->evidence->value,
        ];
        if ($observation->changedFields->count() > 0) {
            $entry['changed_fields'] = $observation->changedFields->values();
        }
        if ($observation->reasonCode !== null) {
            $entry['reason_code'] = $observation->reasonCode->value;
        }

        return $entry;
    }
}
