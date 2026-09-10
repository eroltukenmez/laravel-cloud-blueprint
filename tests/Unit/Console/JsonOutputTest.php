<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Console;

use LaravelCloudBlueprint\Console\JsonError;
use LaravelCloudBlueprint\Console\JsonErrorCategory;
use LaravelCloudBlueprint\Console\JsonOutput;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

final class JsonOutputTest extends TestCase
{
    public function testCallerOwnsItsCommandLocalContractVersion(): void
    {
        $output = new BufferedOutput();

        self::assertTrue((new JsonOutput())->write(['contract_version' => 99, 'status' => 'success'], $output, 7));

        $decoded = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame(7, $decoded['contract_version']);
        self::assertSame('success', $decoded['status']);
        self::assertArrayNotHasKey('cli_contract_version', $decoded);
    }

    public function testErrorUsesTheCommonEnvelope(): void
    {
        $output = new BufferedOutput();
        $error = new JsonError(
            JsonErrorCategory::BLUEPRINT,
            'blueprint_invalid',
            'Blueprint validation failed.',
            ['validation_errors' => [['path' => 'organization', 'code' => 'required', 'message' => 'Required.']]],
        );

        self::assertTrue((new JsonOutput())->writeError($error, $output, 1));

        $decoded = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame(1, $decoded['contract_version']);
        self::assertSame('error', $decoded['status']);
        self::assertIsArray($decoded['error']);
        self::assertSame('blueprint', $decoded['error']['category']);
        self::assertSame('blueprint_invalid', $decoded['error']['code']);
        self::assertIsArray($decoded['error']['validation_errors']);
    }

    public function testEncodingFailureEmitsOneKnownSafeVersionedErrorDocument(): void
    {
        $output = new BufferedOutput();

        self::assertFalse((new JsonOutput())->write(['status' => 'success', 'message' => "invalid-\xB1"], $output, 1));

        $rendered = $output->fetch();
        $decoded = json_decode($rendered, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame(1, $decoded['contract_version']);
        self::assertSame('error', $decoded['status']);
        self::assertIsArray($decoded['error']);
        self::assertSame('output', $decoded['error']['category']);
        self::assertSame('json_encoding_failed', $decoded['error']['code']);
        self::assertSame(1, substr_count($rendered, '"contract_version"'));
    }
}
