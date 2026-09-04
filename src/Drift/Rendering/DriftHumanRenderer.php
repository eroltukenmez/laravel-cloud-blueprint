<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Drift\Rendering;

use LaravelCloudBlueprint\Drift\DriftReport;
use LaravelCloudBlueprint\Observation\ObservationKind;
use LaravelCloudBlueprint\Observation\OwnershipStatus;
use LaravelCloudBlueprint\Observation\ResourceObservation;

final readonly class DriftHumanRenderer
{
    public function render(DriftReport $report): string
    {
        $lines = ['Laravel Cloud Blueprint Observations', ''];
        $visible = array_values(array_filter(
            iterator_to_array($report->observations, false),
            self::isVisible(...),
        ));

        if ($visible === []) {
            $lines[] = 'No differences observed.';
        } else {
            foreach ($visible as $observation) {
                $lines[] = (string) $observation->address;
                $lines[] = '  Observation: ' . self::label($observation->observation);
                $lines[] = '  Ownership: ' . $observation->ownership->value;
                $lines[] = '  Reconciliation: ' . $observation->reconciliation->value;
                $lines[] = '  Evidence: ' . $observation->evidence->value;
                if ($observation->changedFields->count() > 0) {
                    $lines[] = '  Changed fields: ' . implode(', ', $observation->changedFields->values());
                }
                $lines[] = '';
            }
        }

        $summary = $report->summary;
        $parts = [
            sprintf('%d scoped', $summary->total()),
            sprintf('%d in sync', $summary->inSync),
        ];
        foreach ([
            'configuration differences' => $summary->configurationDifference,
            'desired resources missing' => $summary->desiredResourceMissing,
            'desired resources absent' => $summary->desiredResourceAbsent,
            'identities missing' => $summary->identityMissing,
            'identity replacements' => $summary->identityReplacement,
            'identity conflicts' => $summary->identityConflict,
            'lifecycle conditions' => $summary->lifecycleCondition,
            'unknown' => $summary->unknown,
        ] as $label => $count) {
            if ($count > 0) {
                $parts[] = sprintf('%d %s', $count, $label);
            }
        }
        if ($visible === []) {
            $lines[] = '';
        }
        $lines[] = 'Summary: ' . implode(', ', $parts) . '.';

        return implode("\n", $lines);
    }

    private static function isVisible(ResourceObservation $observation): bool
    {
        return $observation->observation !== ObservationKind::IN_SYNC
            || $observation->ownership === OwnershipStatus::UNMANAGED;
    }

    private static function label(ObservationKind $kind): string
    {
        return match ($kind) {
            ObservationKind::IN_SYNC => 'In sync (unmanaged)',
            ObservationKind::DESIRED_RESOURCE_MISSING => 'Desired resource missing',
            ObservationKind::DESIRED_RESOURCE_ABSENT => 'Desired resource absent',
            ObservationKind::CONFIGURATION_DIFFERENCE => 'Configuration difference',
            ObservationKind::IDENTITY_MISSING => 'Managed identity missing',
            ObservationKind::IDENTITY_REPLACEMENT => 'Identity replacement',
            ObservationKind::IDENTITY_CONFLICT => 'Identity conflict',
            ObservationKind::LIFECYCLE_CONDITION => 'Lifecycle condition',
            ObservationKind::UNKNOWN => 'Unknown / incomplete evidence',
        };
    }
}
