<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\State;

enum StateVersion: int
{
    case V1 = 1;
    case V2 = 2;

    public const self CURRENT = self::V2;
}
