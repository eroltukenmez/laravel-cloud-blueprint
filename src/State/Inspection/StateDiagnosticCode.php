<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\State\Inspection;

enum StateDiagnosticCode: string
{
    case LOCAL_OWNERSHIP_VALID = 'local_ownership_valid';
    case LEGACY_STATE_FORMAT = 'legacy_state_format';
    case IDENTITY_CONFLICT = 'identity_conflict';
    case HISTORICAL_PROVENANCE_UNAVAILABLE = 'historical_provenance_unavailable';
    case REMOTE_OWNERSHIP_VERIFIED = 'remote_ownership_verified';
    case REMOTE_IDENTITY_MISSING = 'remote_identity_missing';
    case REMOTE_IDENTITY_REPLACEMENT = 'remote_identity_replacement';
    case PARENT_CHILD_IDENTITY_CONFLICT = 'parent_child_identity_conflict';
    case EVIDENCE_INCOMPLETE = 'evidence_incomplete';
    case UNMANAGED_REMOTE_CHILD = 'unmanaged_remote_child';
}
