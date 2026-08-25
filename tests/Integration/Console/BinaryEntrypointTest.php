<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Integration\Console;

use PHPUnit\Framework\TestCase;

final class BinaryEntrypointTest extends TestCase
{
    private const string VERSION_OUTPUT = 'Laravel Cloud Blueprint 0.1.0-alpha.1';

    public function testRepositoryBinaryUsesLocalVendorAutoloadFallback(): void
    {
        [$exitCode, $output] = $this->runProcess([
            PHP_BINARY,
            dirname(__DIR__, 3) . '/bin/lcb',
            '--version',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString(self::VERSION_OUTPUT, $output);
    }

    public function testComposerProxyAutoloadPathWorksWithoutPackageLocalVendorDirectory(): void
    {
        $temporaryDirectory = sys_get_temp_dir() . '/lcb-bin-test-' . bin2hex(random_bytes(8));
        $packageBinDirectory = $temporaryDirectory . '/package/bin';
        self::assertTrue(mkdir($packageBinDirectory, 0777, true));

        $sourceBinary = dirname(__DIR__, 3) . '/bin/lcb';
        $copiedBinary = $packageBinDirectory . '/lcb';
        self::assertTrue(copy($sourceBinary, $copiedBinary));

        $proxy = $temporaryDirectory . '/proxy.php';
        $proxyContents = sprintf(
            "<?php\n\$GLOBALS['_composer_autoload_path'] = %s;\ninclude %s;\n",
            var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true),
            var_export($copiedBinary, true),
        );
        self::assertNotFalse(file_put_contents($proxy, $proxyContents));

        try {
            [$exitCode, $output] = $this->runProcess([PHP_BINARY, $proxy, '--version']);

            self::assertSame(0, $exitCode);
            self::assertStringContainsString(self::VERSION_OUTPUT, $output);
            self::assertDirectoryDoesNotExist($temporaryDirectory . '/package/vendor');
        } finally {
            unlink($proxy);
            unlink($copiedBinary);
            rmdir($packageBinDirectory);
            rmdir($temporaryDirectory . '/package');
            rmdir($temporaryDirectory);
        }
    }

    /**
     * @param list<string> $command
     * @return array{int, string}
     */
    private function runProcess(array $command): array
    {
        $pipes = [];
        $process = proc_open($command, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (!is_resource($process)
            || !isset($pipes[1], $pipes[2])
            || !is_resource($pipes[1])
            || !is_resource($pipes[2])) {
            self::fail('Unable to start the binary subprocess.');
        }

        $standardOutput = stream_get_contents($pipes[1]);
        $standardError = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertNotFalse($standardOutput);
        self::assertNotFalse($standardError);

        return [proc_close($process), $standardOutput . $standardError];
    }
}
