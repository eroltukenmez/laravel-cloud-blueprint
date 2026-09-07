<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\State\Inspection;

enum DiagnosticSeverity: string
{
    case INFO = 'info';
    case WARNING = 'warning';
    case ERROR = 'error';
}
