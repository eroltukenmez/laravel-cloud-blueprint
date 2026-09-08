<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Cloud\DTO;

/** Read-only result of a scoped logical Database listing, including pagination evidence. */
final readonly class CloudDatabaseScopedList
{
    /** @param list<CloudDatabase> $databases */
    public function __construct(
        public array $databases,
        public CloudDatabaseScopedListStatus $status,
        public CloudDatabaseScopedPaginationStatus $paginationStatus,
    ) {
    }
}
