<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\DTO;

enum CloudDatabaseScopedListStatus: string
{
    case COMPLETE = 'complete';
    case PARTIAL = 'partial';
    case MALFORMED = 'malformed';
    case FAILED = 'failed';
}
