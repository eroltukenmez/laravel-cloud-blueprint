<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Infrastructure\File;

use LaravelCloudBlueprint\Application\File\FileOperationException;
use LaravelCloudBlueprint\Application\File\FileReader;
use LaravelCloudBlueprint\Application\File\FileWriter;

final readonly class NativeFileSystem implements FileReader, FileWriter
{
    public function exists(string $path): bool
    {
        return file_exists($path);
    }

    public function read(string $path): string
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new FileOperationException(sprintf('Unable to read file "%s".', $path));
        }

        return $contents;
    }

    public function write(string $path, string $contents): void
    {
        if (@file_put_contents($path, $contents) === false) {
            throw new FileOperationException(sprintf('Unable to write file "%s".', $path));
        }
    }
}
