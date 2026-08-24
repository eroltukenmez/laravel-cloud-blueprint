<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Infrastructure\Yaml;

use LaravelCloudBlueprint\Blueprint\Decoder\StructuredDataDecoder;
use LaravelCloudBlueprint\Blueprint\Decoder\StructuredDataDecodingException;
use stdClass;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final readonly class SymfonyYamlDecoder implements StructuredDataDecoder
{
    /** @return array<string, mixed> */
    public function decode(string $content): array
    {
        try {
            $decoded = Yaml::parse($content, Yaml::PARSE_OBJECT_FOR_MAP);
        } catch (ParseException $exception) {
            throw new StructuredDataDecodingException(
                'The structured data could not be decoded.',
                previous: $exception,
            );
        }

        if (!$decoded instanceof stdClass) {
            throw new StructuredDataDecodingException('The structured data root must be a mapping.');
        }

        return $this->mappingToArray($decoded);
    }

    /** @return array<string, mixed> */
    private function mappingToArray(stdClass $mapping): array
    {
        $result = [];

        foreach (get_object_vars($mapping) as $key => $value) {
            if (!is_string($key)) {
                throw new StructuredDataDecodingException('A mapping key must be a string.');
            }

            $result[$key] = $this->convertMappings($value);
        }

        return $result;
    }

    private function convertMappings(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            return $this->mappingToArray($value);
        }

        if (is_array($value)) {
            return array_map($this->convertMappings(...), $value);
        }

        return $value;
    }
}
