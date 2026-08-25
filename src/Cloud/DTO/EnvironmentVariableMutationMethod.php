<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\DTO;

enum EnvironmentVariableMutationMethod: string
{
    /**
     * Laravel Cloud SET creates missing keys and updates existing keys. It is
     * not conditional, so remote changes between plan and apply may be overwritten.
     */
    case SET = 'set';
}
