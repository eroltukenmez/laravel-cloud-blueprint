<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\Exception;

final class CloudValidationException extends CloudApiException
{
    /**
     * @param array<string, list<string>> $fieldErrors
     */
    public function __construct(
        public readonly ?string $apiMessage,
        public readonly array $fieldErrors,
        string $method,
        string $path,
        ?int $statusCode = null,
        ?string $requestId = null,
    ) {
        parent::__construct(
            'Laravel Cloud rejected the request.',
            $method,
            $path,
            $statusCode,
            $requestId,
        );
    }
}
