<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\State\Inspection;

enum DiagnosticEvidenceSource: string
{
    case LOCAL_STATE = 'local_state';
    case SOURCE_VERSION = 'source_version';
}
