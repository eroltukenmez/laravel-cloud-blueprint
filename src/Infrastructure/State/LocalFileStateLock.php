<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Infrastructure\State;

use LaravelCloudBlueprint\State\Contract\StateLock;
use LaravelCloudBlueprint\State\Contract\StateLockHandle;
use LaravelCloudBlueprint\State\Exception\StateLockedException;
use LaravelCloudBlueprint\State\Exception\StateStorageException;

final readonly class LocalFileStateLock implements StateLock
{
    public function __construct(private string $path)
    {
    }

    public function acquire(): StateLockHandle
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new StateStorageException(sprintf('Unable to create state directory "%s".', $directory));
        }

        $handle = @fopen($this->path, 'c+');
        if ($handle === false) {
            throw new StateStorageException(sprintf('Unable to open state lock "%s".', $this->path));
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new StateLockedException('Local state is locked by another writer.');
        }

        return new LocalFileStateLockHandle($handle);
    }
}

final class LocalFileStateLockHandle implements StateLockHandle
{
    /** @var resource|null */
    private $handle;

    /** @param resource $handle */
    public function __construct($handle)
    {
        $this->handle = $handle;
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
