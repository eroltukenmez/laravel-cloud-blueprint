<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\State;

use InvalidArgumentException;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use LaravelCloudBlueprint\State\StateDocument;
use LaravelCloudBlueprint\State\StateResource;
use LaravelCloudBlueprint\State\StateVersion;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;

final class StateDocumentTest extends TestCase
{
    public function testEmptyStateAndAddressBasedLookupAreDeterministic(): void
    {
        $state = StateDocument::empty();

        self::assertSame(0, $state->serial);
        self::assertNull($state->organization);
        self::assertSame([], $state->resources());

        $address = new ResourceAddress(ResourceType::APPLICATION, 'my-api');
        self::assertNull($state->find($address));

        $this->expectException(OutOfBoundsException::class);
        $state->get($address);
    }

    public function testItStoresApplicationAndEnvironmentIdentityByFullAddress(): void
    {
        $applicationAddress = new ResourceAddress(ResourceType::APPLICATION, 'my-api');
        $environmentAddress = new ResourceAddress(ResourceType::ENVIRONMENT, 'production');
        $application = new StateResource($applicationAddress, ResourceType::APPLICATION, 'app_123');
        $environment = new StateResource(
            $environmentAddress,
            ResourceType::ENVIRONMENT,
            'env_456',
            $applicationAddress,
        );

        $state = StateDocument::empty()
            ->withOrganization('my-organization')
            ->withResource($environment)
            ->withResource($application);

        self::assertSame('app_123', $state->get($applicationAddress)->remoteId);
        self::assertSame('env_456', $state->get($environmentAddress)->remoteId);
        self::assertSame('application.my-api', (string) $state->get($environmentAddress)->parent);
        self::assertSame(
            ['application.my-api', 'environment.production'],
            array_map(static fn (StateResource $resource): string => (string) $resource->address, $state->resources()),
        );
    }

    public function testWithResourceDeterministicallyReplacesTheSameAddress(): void
    {
        $address = new ResourceAddress(ResourceType::APPLICATION, 'my-api');
        $state = StateDocument::empty()
            ->withResource(new StateResource($address, ResourceType::APPLICATION, 'app_old'))
            ->withResource(new StateResource($address, ResourceType::APPLICATION, 'app_new'));

        self::assertCount(1, $state->resources());
        self::assertSame('app_new', $state->get($address)->remoteId);
    }

    public function testConstructorRejectsDuplicateAddresses(): void
    {
        $address = new ResourceAddress(ResourceType::APPLICATION, 'my-api');

        $this->expectException(InvalidArgumentException::class);

        new StateDocument(
            StateVersion::V1,
            0,
            'acme',
            new StateResource($address, ResourceType::APPLICATION, 'app_1'),
            new StateResource($address, ResourceType::APPLICATION, 'app_2'),
        );
    }

    public function testStateResourceRejectsATypeThatDoesNotMatchItsAddress(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StateResource(
            new ResourceAddress(ResourceType::APPLICATION, 'my-api'),
            ResourceType::ENVIRONMENT,
            'env_123',
        );
    }

    public function testPlanningOnlyDatabaseResourceTypesCannotBePersisted(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StateResource(
            new ResourceAddress(ResourceType::DATABASE_CLUSTER, 'primary'),
            ResourceType::DATABASE_CLUSTER,
            'cluster-1',
        );
    }
}
