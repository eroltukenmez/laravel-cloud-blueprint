<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Infrastructure\Http;

use LaravelCloudBlueprint\Cloud\CloudApiToken;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudClient;
use LaravelCloudBlueprint\Cloud\Contract\LaravelCloudClientFactory;
use Symfony\Component\HttpClient\HttpClient;

final readonly class SymfonyLaravelCloudClientFactory implements LaravelCloudClientFactory
{
    public function create(CloudApiToken $token): LaravelCloudClient
    {
        return new SymfonyLaravelCloudClient(HttpClient::create(), $token);
    }
}
