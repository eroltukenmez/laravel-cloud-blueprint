<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\Contract;

use LaravelCloudBlueprint\Cloud\CloudApiToken;

/** Creates the capability-narrow Cloud reader used exclusively by state:inspect. */
interface StateInspectionCloudReaderFactory
{
    public function create(CloudApiToken $token): StateInspectionCloudReader;
}
