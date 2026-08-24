<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Infrastructure\Environment;

use LaravelCloudBlueprint\Cloud\CloudApiToken;
use LaravelCloudBlueprint\Cloud\Contract\CloudTokenProvider;

final readonly class LcbTokenProvider implements CloudTokenProvider
{
    public function token(): ?CloudApiToken
    {
        $value = getenv('LCB_TOKEN');

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return new CloudApiToken($value);
    }
}
