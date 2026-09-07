<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\State\Inspection;

use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\State\StateResource;
use LaravelCloudBlueprint\State\StateVersion;

final readonly class InspectLocalState
{
    public function inspect(LoadedState $loaded): StateInspectionReport
    {
        $diagnostics = [];
        if ($loaded->sourceVersion === StateVersion::V1) {
            $diagnostics[] = new StateDiagnostic(
                DiagnosticSeverity::WARNING,
                StateDiagnosticCode::LEGACY_STATE_FORMAT,
                DiagnosticEvidenceSource::SOURCE_VERSION,
                RecoveryDisposition::MANUAL_DECISION_REQUIRED,
            );
        }

        $conflictedAddresses = $this->conflictedAddresses($loaded->document->resources());
        foreach ($loaded->document->resources() as $resource) {
            $address = (string) $resource->address;
            if (isset($conflictedAddresses[$address])) {
                $diagnostics[] = new StateDiagnostic(
                    DiagnosticSeverity::ERROR,
                    StateDiagnosticCode::IDENTITY_CONFLICT,
                    DiagnosticEvidenceSource::LOCAL_STATE,
                    RecoveryDisposition::MANUAL_DECISION_REQUIRED,
                    $resource->address,
                );
                continue;
            }

            if ($loaded->sourceVersion === StateVersion::V1 && $resource->type === ResourceType::DATABASE) {
                $diagnostics[] = new StateDiagnostic(
                    DiagnosticSeverity::WARNING,
                    StateDiagnosticCode::HISTORICAL_PROVENANCE_UNAVAILABLE,
                    DiagnosticEvidenceSource::SOURCE_VERSION,
                    RecoveryDisposition::UNAVAILABLE,
                    $resource->address,
                );
                continue;
            }

            $diagnostics[] = new StateDiagnostic(
                DiagnosticSeverity::INFO,
                StateDiagnosticCode::LOCAL_OWNERSHIP_VALID,
                DiagnosticEvidenceSource::LOCAL_STATE,
                RecoveryDisposition::NONE,
                $resource->address,
                $resource->classification,
                $resource->provenance,
            );
        }

        return new StateInspectionReport(...$diagnostics);
    }

    /**
     * @param list<StateResource> $resources
     * @return array<string, true>
     */
    private function conflictedAddresses(array $resources): array
    {
        /** @var array<string, list<StateResource>> $byRemoteId */
        $byRemoteId = [];
        foreach ($resources as $resource) {
            $byRemoteId[$resource->remoteId][] = $resource;
        }

        $addresses = [];
        foreach ($byRemoteId as $sameIdentityResources) {
            if (count($sameIdentityResources) < 2) {
                continue;
            }
            foreach ($sameIdentityResources as $resource) {
                $addresses[(string) $resource->address] = true;
            }
        }

        return $addresses;
    }
}
