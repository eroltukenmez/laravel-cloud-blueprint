<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Blueprint\Validation;

final readonly class LogicalAddressSegmentPolicy
{
    public function violation(string $value, bool $dotsAllowed): ?string
    {
        if (!$dotsAllowed && str_contains($value, '.')) {
            return 'must not contain dots';
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return 'must not contain ASCII control characters or DEL';
        }

        return null;
    }

    public function display(string $value): string
    {
        return preg_replace_callback(
            '/[\x00-\x1F\x7F]/',
            static fn (array $match): string => sprintf('\\x%02X', ord($match[0])),
            $value,
        ) ?? $value;
    }
}
