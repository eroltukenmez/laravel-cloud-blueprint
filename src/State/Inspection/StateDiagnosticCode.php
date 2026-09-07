<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\State\Inspection;

enum StateDiagnosticCode: string
{
    case LOCAL_OWNERSHIP_VALID = 'local_ownership_valid';
    case LEGACY_STATE_FORMAT = 'legacy_state_format';
    case IDENTITY_CONFLICT = 'identity_conflict';
    case HISTORICAL_PROVENANCE_UNAVAILABLE = 'historical_provenance_unavailable';
}
