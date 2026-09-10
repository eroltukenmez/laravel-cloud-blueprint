<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Console;

use InvalidArgumentException;

final readonly class JsonError
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public JsonErrorCategory $category,
        public string $code,
        public string $message,
        public array $details = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/', $code) !== 1) {
            throw new InvalidArgumentException('JSON error codes must be stable snake-case identifiers.');
        }

        foreach (['category', 'code', 'message'] as $reserved) {
            if (array_key_exists($reserved, $details)) {
                throw new InvalidArgumentException(sprintf('JSON error detail "%s" is reserved.', $reserved));
            }
        }
    }

    /** @return array{status: string, error: array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'status' => 'error',
            'error' => [
                'category' => $this->category->value,
                'code' => $this->code,
                'message' => $this->message,
                ...$this->details,
            ],
        ];
    }
}
