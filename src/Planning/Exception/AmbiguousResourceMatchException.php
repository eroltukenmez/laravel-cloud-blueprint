<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Planning\Exception;

use RuntimeException;

final class AmbiguousResourceMatchException extends RuntimeException
{
    public function __construct(string $resourceType, string $name)
    {
        parent::__construct(sprintf(
            'Multiple remote %s resources match the name "%s".',
            $resourceType,
            $name,
        ));
    }
}
