<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\State\Contract;

use LaravelCloudBlueprint\State\StateDocument;

interface StateTransaction
{
    public function load(): StateDocument;

    public function save(StateDocument $state): StateDocument;

    public function release(): void;
}
