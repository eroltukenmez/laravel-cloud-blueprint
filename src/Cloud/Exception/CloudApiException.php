<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\Exception;

class CloudApiException extends CloudException
{
    public function __construct(
        string $message,
        public readonly string $method,
        public readonly string $path,
        public readonly ?int $statusCode = null,
        public readonly ?string $requestId = null,
    ) {
        parent::__construct($message);
    }
}
