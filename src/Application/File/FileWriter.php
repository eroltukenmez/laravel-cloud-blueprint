<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Application\File;

interface FileWriter
{
    public function write(string $path, string $contents): void;
}
