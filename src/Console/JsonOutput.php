<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Console;

use JsonException;
use Symfony\Component\Console\Output\OutputInterface;

final readonly class JsonOutput
{
    private const string ENCODING_FAILURE = <<<'JSON'
{
    "status": "error",
    "message": "Unable to encode command output as JSON."
}
JSON;

    /** @param array<string, mixed> $payload */
    public function write(array $payload, OutputInterface $output): bool
    {
        try {
            $encoded = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException) {
            $output->writeln(self::ENCODING_FAILURE);
            return false;
        }

        $output->writeln($encoded);
        return true;
    }
}
