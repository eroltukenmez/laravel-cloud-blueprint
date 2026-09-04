<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Observation;

use InvalidArgumentException;
use LaravelCloudBlueprint\Observation\EvidenceStatus;
use LaravelCloudBlueprint\Observation\ObservationKind;
use LaravelCloudBlueprint\Observation\OwnershipStatus;
use LaravelCloudBlueprint\Observation\ReconciliationStatus;
use LaravelCloudBlueprint\Observation\ResourceObservation;
use LaravelCloudBlueprint\Observation\ResourceObservationCollection;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use PHPUnit\Framework\TestCase;

final class ResourceObservationCollectionTest extends TestCase
{
    public function testObservationsAreOrderedByDomainResourceTypeThenAddress(): void
    {
        $collection = new ResourceObservationCollection(
            self::observation(ResourceType::VARIABLE, 'production.APP_ENV'),
            self::observation(ResourceType::DATABASE, 'primary.application'),
            self::observation(ResourceType::APPLICATION, 'zeta'),
            self::observation(ResourceType::ENVIRONMENT, 'production'),
            self::observation(ResourceType::APPLICATION, 'alpha'),
            self::observation(ResourceType::DATABASE_CLUSTER, 'primary'),
            self::observation(ResourceType::DATABASE_ATTACHMENT, 'production'),
        );

        self::assertSame(7, $collection->count());
        self::assertSame([
            'application.alpha',
            'application.zeta',
            'environment.production',
            'database_cluster.primary',
            'database.primary.application',
            'database_attachment.production',
            'variable.production.APP_ENV',
        ], array_map(
            static fn (ResourceObservation $observation): string => (string) $observation->address,
            iterator_to_array($collection, false),
        ));
    }

    public function testDuplicateObservationAddressIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unique addresses');

        new ResourceObservationCollection(
            self::observation(ResourceType::ENVIRONMENT, 'production'),
            self::observation(ResourceType::ENVIRONMENT, 'production'),
        );
    }

    private static function observation(ResourceType $type, string $name): ResourceObservation
    {
        return new ResourceObservation(
            new ResourceAddress($type, $name),
            ObservationKind::IN_SYNC,
            OwnershipStatus::MANAGED,
            ReconciliationStatus::NOT_APPLICABLE,
            EvidenceStatus::COMPLETE,
        );
    }
}
