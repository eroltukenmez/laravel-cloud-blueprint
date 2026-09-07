<?php

declare(strict_types=1);
namespace LaravelCloudBlueprint\State\Inspection;
final readonly class StateInspectionCheckResult { public function __construct(public int $failingCount) {} public function passed(): bool { return $this->failingCount === 0; } }
