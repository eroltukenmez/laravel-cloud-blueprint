<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Planning;

use InvalidArgumentException;

final readonly class ResourceAddress
{
    public function __construct(
        public ResourceType $type,
        public string $name,
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Resource address name must not be empty.');
        }
    }

    public static function fromString(string $address): self
    {
        $separator = strpos($address, '.');

        if ($separator === false) {
            throw new InvalidArgumentException(sprintf('Invalid resource address "%s".', $address));
        }

        $type = ResourceType::tryFrom(substr($address, 0, $separator));
        $name = substr($address, $separator + 1);

        if ($type === null || trim($name) === '') {
            throw new InvalidArgumentException(sprintf('Invalid resource address "%s".', $address));
        }

        return new self($type, $name);
    }

    public function __toString(): string
    {
        return $this->type->value . '.' . $this->name;
    }
}
