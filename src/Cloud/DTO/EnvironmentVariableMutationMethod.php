<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\DTO;

enum EnvironmentVariableMutationMethod: string
{
    /**
     * LCB plans SET only for keys observed as missing. Laravel Cloud does not
     * document conditional creation, so another actor creating the key between
     * plan and apply could cause SET to update that newly created value.
     */
    case SET = 'set';
}
