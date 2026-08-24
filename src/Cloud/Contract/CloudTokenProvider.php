<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\Contract;

use LaravelCloudBlueprint\Cloud\CloudApiToken;

interface CloudTokenProvider
{
    public function token(): ?CloudApiToken;
}
