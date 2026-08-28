<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Application\Import;

enum ImportStatus: string
{
    case IMPORTABLE = 'importable';
    case ALREADY_MANAGED = 'already_managed';
    case CONFLICT = 'conflict';
    case UNSUPPORTED = 'unsupported';
}
