<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Blueprint;

use InvalidArgumentException;

final readonly class DatabaseReference
{
    public function __construct(
        public string $cluster,
        public string $database,
    ) {
        if (trim($cluster) === '' || trim($database) === '') {
            throw new InvalidArgumentException('A Database reference requires non-empty Cluster and Database names.');
        }
    }

    public static function fromString(string $reference): self
    {
        $parts = explode('.', $reference);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException(sprintf('Invalid Database reference "%s".', $reference));
        }

        return new self($parts[0], $parts[1]);
    }

    public function __toString(): string
    {
        return $this->cluster . '.' . $this->database;
    }
}
