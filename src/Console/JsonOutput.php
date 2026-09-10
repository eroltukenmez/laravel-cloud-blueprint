<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Console;

use JsonException;
use Symfony\Component\Console\Output\OutputInterface;

final readonly class JsonOutput
{
    private const string ENCODING_FAILURE = <<<'JSON'
{
    "contract_version": %d,
    "status": "error",
    "error": {
        "category": "output",
        "code": "json_encoding_failed",
        "message": "Unable to encode command output as JSON."
    }
}
JSON;

    /** @param array<string, mixed> $payload */
    public function write(array $payload, OutputInterface $output, int $contractVersion): bool
    {
        try {
            $encoded = json_encode(
                ['contract_version' => $contractVersion] + $payload,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException) {
            $output->writeln(sprintf(self::ENCODING_FAILURE, $contractVersion));
            return false;
        }

        $output->writeln($encoded);
        return true;
    }

    public function writeError(JsonError $error, OutputInterface $output, int $contractVersion): bool
    {
        return $this->write($error->toArray(), $output, $contractVersion);
    }
}
