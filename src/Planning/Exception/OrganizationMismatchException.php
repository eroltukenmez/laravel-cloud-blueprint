<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Planning\Exception;

use RuntimeException;

final class OrganizationMismatchException extends RuntimeException
{
    public function __construct(string $desiredSlug, string $remoteSlug)
    {
        parent::__construct(sprintf(
            'Blueprint organization "%s" does not match authenticated Laravel Cloud organization "%s".',
            $desiredSlug,
            $remoteSlug,
        ));
    }
}
