<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Application\File;

interface FileReader
{
    public function exists(string $path): bool;

    public function read(string $path): string;
}
