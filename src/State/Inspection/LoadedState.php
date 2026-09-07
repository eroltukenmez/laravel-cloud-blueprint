<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\State\Inspection;

use LaravelCloudBlueprint\State\StateDocument;
use LaravelCloudBlueprint\State\StateVersion;

final readonly class LoadedState
{
    public function __construct(
        public StateDocument $document,
        public StateVersion $sourceVersion,
    ) {
    }
}
