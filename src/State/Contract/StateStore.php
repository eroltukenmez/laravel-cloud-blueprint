<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\State\Contract;

use LaravelCloudBlueprint\State\StateDocument;

interface StateStore
{
    public function load(): StateDocument;

    public function save(StateDocument $state): StateDocument;
}
