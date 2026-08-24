<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\Contract;

use LaravelCloudBlueprint\Cloud\CloudApiToken;

interface LaravelCloudClientFactory
{
    public function create(CloudApiToken $token): LaravelCloudClient;
}
