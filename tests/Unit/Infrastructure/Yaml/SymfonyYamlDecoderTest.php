<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Infrastructure\Yaml;

use LaravelCloudBlueprint\Blueprint\Decoder\StructuredDataDecodingException;
use LaravelCloudBlueprint\Infrastructure\Yaml\SymfonyYamlDecoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SymfonyYamlDecoderTest extends TestCase
{
    public function testItDecodesAMapping(): void
    {
        self::assertSame(
            ['version' => 1, 'application' => ['name' => 'example']],
            (new SymfonyYamlDecoder())->decode("version: 1\napplication:\n  name: example\n"),
        );
    }

    public function testInvalidYamlThrowsAProjectOwnedException(): void
    {
        $this->expectException(StructuredDataDecodingException::class);

        (new SymfonyYamlDecoder())->decode("application:\n  name: example\n invalid: indentation\n");
    }

    /** @return iterable<string, array{string}> */
    public static function nonMappingRootProvider(): iterable
    {
        yield 'scalar' => ['blueprint'];
        yield 'list' => ["- first\n- second\n"];
    }

    #[DataProvider('nonMappingRootProvider')]
    public function testItRejectsANonMappingRoot(string $yaml): void
    {
        $this->expectException(StructuredDataDecodingException::class);
        $this->expectExceptionMessage('The structured data root must be a mapping.');

        (new SymfonyYamlDecoder())->decode($yaml);
    }
}
