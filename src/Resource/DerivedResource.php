<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Resource;

use InvalidArgumentException;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;

final class DerivedResource
{
    public const string DEFAULT_DATABASE_NAME = '__derived_default';

    public static function defaultDatabaseAddress(ResourceAddress $cluster): ResourceAddress
    {
        if ($cluster->type !== ResourceType::DATABASE_CLUSTER) {
            throw new InvalidArgumentException('A derived default Database requires a Database Cluster address.');
        }

        return new ResourceAddress(
            ResourceType::DATABASE,
            $cluster->name . '.' . self::DEFAULT_DATABASE_NAME,
        );
    }
}
