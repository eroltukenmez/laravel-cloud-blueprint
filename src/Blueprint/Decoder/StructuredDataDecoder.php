<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Blueprint\Decoder;

interface StructuredDataDecoder
{
    /** @return array<string, mixed> */
    public function decode(string $content): array;
}
