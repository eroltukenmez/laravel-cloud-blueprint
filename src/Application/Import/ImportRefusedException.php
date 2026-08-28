<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Application\Import;

use RuntimeException;

final class ImportRefusedException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?ImportProposal $proposal = null,
    ) {
        parent::__construct($message);
    }
}
