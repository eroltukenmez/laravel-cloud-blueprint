<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Planning;

use LaravelCloudBlueprint\Cloud\DTO\DatabaseDependencies;
use LaravelCloudBlueprint\Cloud\DTO\DatabaseDestructiveReadiness;
use LaravelCloudBlueprint\Cloud\DTO\EnvironmentDependencies;
use LaravelCloudBlueprint\Cloud\DTO\EnvironmentDestructiveReadiness;

enum PlanReconciliationStatus: string
{
    case SUPPORTED = 'supported';
    case BLOCKED = 'blocked';
    case UNSUPPORTED = 'unsupported';
    case NOT_APPLICABLE = 'not_applicable';

    public static function forAction(
        ResourceType $resourceType,
        PlanOperation $operation,
        EnvironmentDependencies|DatabaseDependencies|null $dependencies,
    ): self {
        if ($operation === PlanOperation::NO_CHANGE) {
            return self::NOT_APPLICABLE;
        }

        if ($operation === PlanOperation::UNSUPPORTED) {
            return self::UNSUPPORTED;
        }

        if ($operation === PlanOperation::CREATE) {
            return $resourceType === ResourceType::DATABASE_ATTACHMENT
                ? self::UNSUPPORTED
                : self::SUPPORTED;
        }

        if ($operation === PlanOperation::UPDATE) {
            return in_array($resourceType, [
                ResourceType::ENVIRONMENT,
                ResourceType::VARIABLE,
                ResourceType::DATABASE_ATTACHMENT,
            ], true) ? self::SUPPORTED : self::UNSUPPORTED;
        }

        if (!in_array($resourceType, [
            ResourceType::ENVIRONMENT,
            ResourceType::DATABASE_CLUSTER,
            ResourceType::DATABASE,
        ], true)) {
            return self::UNSUPPORTED;
        }

        $readiness = $dependencies?->readiness();

        return $readiness === EnvironmentDestructiveReadiness::SAFE
            || $readiness === DatabaseDestructiveReadiness::SAFE
            ? self::SUPPORTED
            : self::BLOCKED;
    }
}
