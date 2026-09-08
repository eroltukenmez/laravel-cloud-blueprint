<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Infrastructure\Http;

use LaravelCloudBlueprint\Cloud\CloudApiToken;
use LaravelCloudBlueprint\Cloud\Contract\StateInspectionCloudReader;
use LaravelCloudBlueprint\Cloud\Contract\StateInspectionCloudReaderFactory;
use Symfony\Component\HttpClient\HttpClient;

final readonly class SymfonyStateInspectionCloudReaderFactory implements StateInspectionCloudReaderFactory
{
    public function create(CloudApiToken $token): StateInspectionCloudReader
    {
        return new SymfonyLaravelCloudClient(HttpClient::create(), $token);
    }
}
