<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Infrastructure\State;

use JsonException;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\State\Contract\StateLock;
use LaravelCloudBlueprint\State\Contract\StateStore;
use LaravelCloudBlueprint\State\Contract\StateTransaction;
use LaravelCloudBlueprint\State\Exception\StateCorruptedException;
use LaravelCloudBlueprint\State\Exception\StateStorageException;
use LaravelCloudBlueprint\State\Inspection\LoadedState;
use LaravelCloudBlueprint\State\StateDocument;
use LaravelCloudBlueprint\State\StateOwnershipClassification;
use LaravelCloudBlueprint\State\StateProvenance;
use LaravelCloudBlueprint\State\StateResource;
use LaravelCloudBlueprint\State\StateVersion;
use Throwable;

final readonly class LocalFileStateStore implements StateStore
{
    public const string DEFAULT_PATH = '.lcb/state.json';

    private StateLock $lock;

    public function __construct(
        private string $path = self::DEFAULT_PATH,
        ?StateLock $lock = null,
    ) {
        $this->lock = $lock ?? new LocalFileStateLock($path . '.lock');
    }

    public function load(): StateDocument
    {
        return $this->loadWithMetadata()->document;
    }

    public function loadWithMetadata(): LoadedState
    {
        if (!file_exists($this->path)) {
            return new LoadedState(StateDocument::empty(), StateVersion::CURRENT);
        }

        $contents = @file_get_contents($this->path);
        if ($contents === false) {
            throw new StateStorageException(sprintf('Unable to read local state "%s".', $this->path));
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new StateCorruptedException('Local state contains invalid JSON.', previous: $exception);
        }

        return $this->decodeDocument($decoded);
    }

    public function save(StateDocument $state): StateDocument
    {
        $handle = $this->lock->acquire();

        try {
            return $this->saveWhileLocked($state);
        } finally {
            $handle->release();
        }
    }

    public function begin(): StateTransaction
    {
        return new LocalFileStateTransaction($this, $this->lock->acquire());
    }

    public function saveWhileLocked(StateDocument $state): StateDocument
    {
        $current = $this->load();
        if ($current->materiallyEquals($state)) {
            return $current;
        }

        if ($state->organization === null) {
            throw new StateStorageException('Cannot save state without an organization.');
        }

        $saved = new StateDocument(
            StateVersion::CURRENT,
            $current->serial + 1,
            $state->organization,
            ...$state->resources(),
        );
        $this->writeAtomically($this->encodeDocument($saved));

        return $saved;
    }

    /** @return array<string, mixed> */
    private function documentAsMapping(mixed $value, string $context): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new StateCorruptedException(sprintf('Expected %s to be a JSON object.', $context));
        }

        $mapping = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new StateCorruptedException(sprintf('%s contains a non-string key.', $context));
            }
            $mapping[$key] = $item;
        }

        return $mapping;
    }

    private function decodeDocument(mixed $decoded): LoadedState
    {
        $root = $this->documentAsMapping($decoded, 'state');
        $this->requireExactFields($root, ['version', 'serial', 'organization', 'resources'], 'state');

        $version = $this->requiredInt($root, 'version', 'state');
        $stateVersion = StateVersion::tryFrom($version);
        if ($stateVersion === null) {
            throw new StateCorruptedException(sprintf(
                'Unsupported state version "%d"; this build can read versions 1 and 2.',
                $version,
            ));
        }

        $serial = $this->requiredInt($root, 'serial', 'state');
        if ($serial < 0) {
            throw new StateCorruptedException('State serial must not be negative.');
        }

        $organization = $this->requiredString($root, 'organization', 'state');
        $resources = [];

        foreach ($this->requiredMapping($root, 'resources', 'state') as $addressValue => $resourceValue) {
            $context = sprintf('resource "%s"', $addressValue);
            $resource = $this->documentAsMapping($resourceValue, $context);
            $allowed = $stateVersion === StateVersion::V1
                ? ['type', 'remote_id', 'parent']
                : ['type', 'remote_id', 'parent', 'classification', 'provenance'];
            $required = $stateVersion === StateVersion::V1
                ? ['type', 'remote_id']
                : ['type', 'remote_id', 'classification'];
            $this->requireAllowedAndRequiredFields($resource, $allowed, $required, $context);

            try {
                $address = ResourceAddress::fromString($addressValue);
                $type = ResourceType::from($this->requiredString($resource, 'type', $context));
                $parentValue = $this->optionalString($resource, 'parent', $context);
                $parent = $parentValue === null ? null : ResourceAddress::fromString($parentValue);
                $classification = $stateVersion === StateVersion::V1
                    ? StateOwnershipClassification::MANAGED
                    : StateOwnershipClassification::from($this->requiredString($resource, 'classification', $context));
                $provenanceValue = $stateVersion === StateVersion::V1
                    ? null
                    : $this->optionalString($resource, 'provenance', $context);
                $provenance = $provenanceValue === null ? null : StateProvenance::from($provenanceValue);
                $resources[] = new StateResource(
                    $address,
                    $type,
                    $this->requiredString($resource, 'remote_id', $context),
                    $parent,
                    $classification,
                    $provenance,
                );
            } catch (Throwable $exception) {
                if ($exception instanceof StateCorruptedException) {
                    throw $exception;
                }
                throw new StateCorruptedException(sprintf('%s is malformed.', ucfirst($context)), previous: $exception);
            }
        }

        try {
            return new LoadedState(
                new StateDocument(StateVersion::CURRENT, $serial, $organization, ...$resources),
                $stateVersion,
            );
        } catch (Throwable $exception) {
            throw new StateCorruptedException('Local state document is malformed.', previous: $exception);
        }
    }

    private function encodeDocument(StateDocument $state): string
    {
        $resources = [];
        foreach ($state->resources() as $resource) {
            $entry = [
                'type' => $resource->type->value,
                'remote_id' => $resource->remoteId,
                'classification' => $resource->classification->value,
            ];
            if ($resource->parent !== null) {
                $entry['parent'] = (string) $resource->parent;
            }
            if ($resource->provenance !== null) {
                $entry['provenance'] = $resource->provenance->value;
            }
            $resources[(string) $resource->address] = $entry;
        }

        try {
            return json_encode([
                'version' => $state->version->value,
                'serial' => $state->serial,
                'organization' => $state->organization,
                'resources' => $resources,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        } catch (JsonException $exception) {
            throw new StateStorageException('Unable to encode local state.', previous: $exception);
        }
    }

    private function writeAtomically(string $contents): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new StateStorageException(sprintf('Unable to create state directory "%s".', $directory));
        }

        $temporaryPath = tempnam($directory, '.state-');
        if ($temporaryPath === false) {
            throw new StateStorageException('Unable to create a temporary state file.');
        }

        try {
            $stream = @fopen($temporaryPath, 'wb');
            if ($stream === false) {
                throw new StateStorageException('Unable to open the temporary state file.');
            }

            try {
                $remaining = $contents;
                while ($remaining !== '') {
                    $written = fwrite($stream, $remaining);
                    if ($written === false || $written === 0) {
                        throw new StateStorageException('Unable to write the temporary state file.');
                    }
                    $remaining = substr($remaining, $written);
                }

                if (!fflush($stream)) {
                    throw new StateStorageException('Unable to flush the temporary state file.');
                }
                if (function_exists('fsync') && !fsync($stream)) {
                    throw new StateStorageException('Unable to synchronize the temporary state file.');
                }
            } finally {
                fclose($stream);
            }

            @chmod($temporaryPath, 0600);
            if (!@rename($temporaryPath, $this->path)) {
                throw new StateStorageException('Unable to atomically replace local state.');
            }
        } finally {
            if (file_exists($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $fields
     */
    private function requireExactFields(array $data, array $fields, string $context): void
    {
        $this->requireAllowedAndRequiredFields($data, $fields, $fields, $context);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $allowed
     * @param list<string> $required
     */
    private function requireAllowedAndRequiredFields(array $data, array $allowed, array $required, string $context): void
    {
        foreach (array_keys($data) as $field) {
            if (!in_array($field, $allowed, true)) {
                throw new StateCorruptedException(sprintf('%s contains unknown field "%s".', ucfirst($context), $field));
            }
        }
        foreach ($required as $field) {
            if (!array_key_exists($field, $data)) {
                throw new StateCorruptedException(sprintf('%s is missing required field "%s".', ucfirst($context), $field));
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function requiredInt(array $data, string $key, string $context): int
    {
        if (!array_key_exists($key, $data) || !is_int($data[$key])) {
            throw new StateCorruptedException(sprintf('%s field "%s" must be an integer.', ucfirst($context), $key));
        }
        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private function requiredString(array $data, string $key, string $context): string
    {
        if (!array_key_exists($key, $data) || !is_string($data[$key]) || trim($data[$key]) === '') {
            throw new StateCorruptedException(sprintf('%s field "%s" must be a non-empty string.', ucfirst($context), $key));
        }
        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private function optionalString(array $data, string $key, string $context): ?string
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }
        return $this->requiredString($data, $key, $context);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function requiredMapping(array $data, string $key, string $context): array
    {
        if (!array_key_exists($key, $data)) {
            throw new StateCorruptedException(sprintf('%s is missing required field "%s".', ucfirst($context), $key));
        }
        return $this->documentAsMapping($data[$key], $context . '.' . $key);
    }
}
