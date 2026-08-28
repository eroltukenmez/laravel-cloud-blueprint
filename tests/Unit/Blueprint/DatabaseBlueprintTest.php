<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Blueprint;

use InvalidArgumentException;
use LaravelCloudBlueprint\Blueprint\DatabaseClusterDefinition;
use LaravelCloudBlueprint\Blueprint\DatabaseClusterDefinitionCollection;
use LaravelCloudBlueprint\Blueprint\DatabaseClusterType;
use LaravelCloudBlueprint\Blueprint\LaravelMySqlConfiguration;
use LaravelCloudBlueprint\Blueprint\LogicalDatabaseDefinition;
use LaravelCloudBlueprint\Blueprint\LogicalDatabaseDefinitionCollection;
use LaravelCloudBlueprint\Blueprint\NeonPostgresConfiguration;
use LaravelCloudBlueprint\Blueprint\Normalization\BlueprintNormalizer;
use LaravelCloudBlueprint\Blueprint\Validation\BlueprintValidator;
use LaravelCloudBlueprint\Blueprint\Validation\ValidationErrorCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DatabaseBlueprintTest extends TestCase
{
    public function testExistingBlueprintRemainsValidAndNormalizesWithoutDatabaseDefinitions(): void
    {
        $data = self::blueprintData();

        self::assertTrue((new BlueprintValidator())->validate($data)->isValid());
        $blueprint = (new BlueprintNormalizer())->normalize($data);
        self::assertCount(0, $blueprint->databaseClusters);
        self::assertNull($blueprint->environments->get('production')->database);
    }

    public function testLaravelMysqlClusterNormalizesToTypedConfiguration(): void
    {
        $blueprint = self::normalizeWithCluster(self::mysqlCluster());
        $cluster = $blueprint->databaseClusters->get('primary');

        self::assertSame(DatabaseClusterType::LARAVEL_MYSQL_8, $cluster->type);
        self::assertSame('eu-central-1', $cluster->region);
        self::assertInstanceOf(LaravelMySqlConfiguration::class, $cluster->configuration);
        self::assertSame('db-flex.m-1vcpu-512mb', $cluster->configuration->size);
        self::assertSame(5, $cluster->configuration->storage);
        self::assertSame(1, $cluster->configuration->retentionDays);
        self::assertFalse($cluster->configuration->usesScheduledSnapshots);
        self::assertFalse($cluster->configuration->isPublic);
    }

    /** @return iterable<string, array{string}> */
    public static function neonTypes(): iterable
    {
        yield 'Postgres 18' => ['neon_serverless_postgres_18'];
        yield 'Postgres 17' => ['neon_serverless_postgres_17'];
    }

    #[DataProvider('neonTypes')]
    public function testNeonClustersNormalizeToTypedConfiguration(string $type): void
    {
        $blueprint = self::normalizeWithCluster(self::neonCluster($type));
        $cluster = $blueprint->databaseClusters->get('primary');

        self::assertSame(DatabaseClusterType::from($type), $cluster->type);
        self::assertInstanceOf(NeonPostgresConfiguration::class, $cluster->configuration);
        self::assertSame(0.25, $cluster->configuration->minimumComputeUnits);
        self::assertSame(1.0, $cluster->configuration->maximumComputeUnits);
        self::assertSame(300, $cluster->configuration->suspendSeconds);
        self::assertSame(7, $cluster->configuration->retentionDays);
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedTypes(): iterable
    {
        yield 'generic mysql' => ['mysql'];
        yield 'RDS MySQL' => ['aws_rds_mysql_8'];
        yield 'RDS Postgres' => ['aws_rds_postgres_18'];
    }

    #[DataProvider('unsupportedTypes')]
    public function testUnsupportedAndRdsTypesAreRejected(string $type): void
    {
        $cluster = self::mysqlCluster();
        $cluster['type'] = $type;

        self::assertValidationError(
            self::withCluster($cluster),
            'database_clusters.primary.type',
            ValidationErrorCode::UNSUPPORTED_DATABASE_TYPE,
        );
    }

    public function testMissingRegionIsRejected(): void
    {
        $cluster = self::mysqlCluster();
        unset($cluster['region']);

        self::assertValidationError(self::withCluster($cluster), 'database_clusters.primary.region', ValidationErrorCode::REQUIRED);
    }

    public function testMissingProviderConfigurationIsRejected(): void
    {
        $cluster = self::withoutClusterConfigValue(self::mysqlCluster(), 'storage');

        self::assertValidationError(self::withCluster($cluster), 'database_clusters.primary.config.storage', ValidationErrorCode::REQUIRED);
    }

    public function testMysqlConfigurationPropertyOnNeonIsRejected(): void
    {
        $cluster = self::withClusterConfigValue(self::neonCluster('neon_serverless_postgres_18'), 'storage', 5);

        self::assertValidationError(self::withCluster($cluster), 'database_clusters.primary.config.storage', ValidationErrorCode::UNKNOWN_PROPERTY);
    }

    public function testNeonConfigurationPropertyOnMysqlIsRejected(): void
    {
        $cluster = self::withClusterConfigValue(self::mysqlCluster(), 'cu_min', 0.25);

        self::assertValidationError(self::withCluster($cluster), 'database_clusters.primary.config.cu_min', ValidationErrorCode::UNKNOWN_PROPERTY);
    }

    public function testUnknownClusterAndConfigurationPropertiesAreRejected(): void
    {
        $cluster = self::mysqlCluster();
        $cluster['unexpected'] = true;
        $cluster = self::withClusterConfigValue($cluster, 'unknown', true);
        $errors = iterator_to_array((new BlueprintValidator())->validate(self::withCluster($cluster)), false);

        self::assertSame(
            ['database_clusters.primary.unexpected', 'database_clusters.primary.config.unknown'],
            array_map(static fn ($error): string => $error->path, $errors),
        );
    }

    public function testLogicalDatabasesAreTypedAndMultipleDefinitionsAreAccepted(): void
    {
        $cluster = self::mysqlCluster();
        $cluster['databases'] = ['reporting' => [], 'application' => []];
        $databases = self::normalizeWithCluster($cluster)->databaseClusters->get('primary')->databases;

        self::assertCount(2, $databases);
        self::assertSame(['application', 'reporting'], array_keys(iterator_to_array($databases)));
        self::assertSame('application', $databases->get('application')->name);
    }

    public function testEmptyLogicalDatabaseNameIsRejected(): void
    {
        $cluster = self::mysqlCluster();
        $cluster['databases'] = ['' => []];

        self::assertValidationError(self::withCluster($cluster), 'database_clusters.primary.databases', ValidationErrorCode::EMPTY_VALUE);
    }

    public function testUnknownLogicalDatabasePropertyIsRejected(): void
    {
        $cluster = self::mysqlCluster();
        $cluster['databases'] = ['application' => ['password' => 'never-normalize-this']];

        self::assertValidationError(
            self::withCluster($cluster),
            'database_clusters.primary.databases.application.password',
            ValidationErrorCode::UNKNOWN_PROPERTY,
        );
    }

    public function testEnvironmentDatabaseReferenceResolvesToTypedCanonicalValue(): void
    {
        $data = self::withEnvironmentDatabase(self::withCluster(self::mysqlCluster()), 'primary.application');
        self::assertTrue((new BlueprintValidator())->validate($data)->isValid());

        $reference = (new BlueprintNormalizer())->normalize($data)->environments->get('production')->database;
        self::assertNotNull($reference);
        self::assertSame('primary', $reference->cluster);
        self::assertSame('application', $reference->database);
        self::assertSame('primary.application', (string) $reference);
    }

    public function testMissingClusterReferenceIsRejected(): void
    {
        $data = self::withEnvironmentDatabase(self::withCluster(self::mysqlCluster()), 'missing.application');

        self::assertValidationError($data, 'environments.production.database', ValidationErrorCode::INVALID_DATABASE_REFERENCE);
    }

    public function testMissingLogicalDatabaseReferenceIsRejected(): void
    {
        $data = self::withEnvironmentDatabase(self::withCluster(self::mysqlCluster()), 'primary.missing');

        self::assertValidationError($data, 'environments.production.database', ValidationErrorCode::INVALID_DATABASE_REFERENCE);
    }

    /** @return iterable<string, array{mixed}> */
    public static function malformedReferences(): iterable
    {
        yield 'no separator' => ['primary'];
        yield 'too many segments' => ['primary.application.extra'];
        yield 'missing cluster' => ['.application'];
        yield 'missing database' => ['primary.'];
        yield 'not a string' => [false];
    }

    #[DataProvider('malformedReferences')]
    public function testMalformedDatabaseReferenceIsRejected(mixed $reference): void
    {
        $data = self::withEnvironmentDatabase(self::withCluster(self::mysqlCluster()), $reference);

        $errors = iterator_to_array((new BlueprintValidator())->validate($data), false);
        self::assertCount(1, $errors);
        self::assertSame('environments.production.database', $errors[0]->path);
    }

    /** @return iterable<string, array{string}> */
    public static function credentialProperties(): iterable
    {
        yield 'password' => ['password'];
        yield 'username' => ['username'];
        yield 'connection URL' => ['database_url'];
        yield 'DSN' => ['dsn'];
        yield 'host' => ['host'];
        yield 'port' => ['port'];
    }

    #[DataProvider('credentialProperties')]
    public function testCredentialAndConnectionPropertiesAreRejected(string $property): void
    {
        $cluster = self::withClusterConfigValue(self::mysqlCluster(), $property, 'secret-value-never-normalized');

        self::assertValidationError(
            self::withCluster($cluster),
            'database_clusters.primary.config.' . $property,
            ValidationErrorCode::UNKNOWN_PROPERTY,
        );
    }

    public function testNormalizedModelContainsNoCredentialFieldsOrValues(): void
    {
        $serialized = serialize(self::normalizeWithCluster(self::mysqlCluster()));

        self::assertStringNotContainsString('password', $serialized);
        self::assertStringNotContainsString('username', $serialized);
        self::assertStringNotContainsString('DATABASE_URL', $serialized);
        self::assertStringNotContainsString('secret-value', $serialized);
    }

    public function testNormalizationSortsClusterAndLogicalDatabaseMappingsDeterministically(): void
    {
        $zeta = self::mysqlCluster();
        $zeta['databases'] = ['zeta' => [], 'alpha' => []];
        $data = self::blueprintData();
        $data['database_clusters'] = ['zeta' => $zeta, 'alpha' => self::neonCluster('neon_serverless_postgres_17')];

        $clusters = (new BlueprintNormalizer())->normalize($data)->databaseClusters;
        self::assertSame(['alpha', 'zeta'], array_keys(iterator_to_array($clusters)));
        self::assertSame(
            ['alpha', 'zeta'],
            array_keys(iterator_to_array($clusters->get('zeta')->databases)),
        );
    }

    public function testTypedCollectionsRejectDuplicateDefinitions(): void
    {
        $databases = new LogicalDatabaseDefinitionCollection(new LogicalDatabaseDefinition('application'));
        $configuration = new LaravelMySqlConfiguration('size', 1, 1, false, false);
        $cluster = new DatabaseClusterDefinition(
            'primary',
            DatabaseClusterType::LARAVEL_MYSQL_8,
            'eu-central-1',
            $configuration,
            $databases,
        );

        $this->expectException(InvalidArgumentException::class);
        new DatabaseClusterDefinitionCollection($cluster, $cluster);
    }

    /** @param array<string, mixed> $cluster */
    private static function normalizeWithCluster(array $cluster): \LaravelCloudBlueprint\Blueprint\Blueprint
    {
        $data = self::withCluster($cluster);
        $validation = (new BlueprintValidator())->validate($data);
        self::assertTrue($validation->isValid());

        return (new BlueprintNormalizer())->normalize($data);
    }

    /**
     * @param array<string, mixed> $cluster
     * @return array<string, mixed>
     */
    private static function withCluster(array $cluster): array
    {
        $data = self::blueprintData();
        $data['database_clusters'] = ['primary' => $cluster];
        return $data;
    }

    /**
     * @param array<string, mixed> $cluster
     * @return array<string, mixed>
     */
    private static function withClusterConfigValue(array $cluster, string $key, mixed $value): array
    {
        $config = $cluster['config'] ?? null;
        if (!is_array($config)) {
            throw new InvalidArgumentException('Test cluster configuration must be an array.');
        }

        $config[$key] = $value;
        $cluster['config'] = $config;
        return $cluster;
    }

    /**
     * @param array<string, mixed> $cluster
     * @return array<string, mixed>
     */
    private static function withoutClusterConfigValue(array $cluster, string $key): array
    {
        $config = $cluster['config'] ?? null;
        if (!is_array($config)) {
            throw new InvalidArgumentException('Test cluster configuration must be an array.');
        }

        unset($config[$key]);
        $cluster['config'] = $config;
        return $cluster;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function withEnvironmentDatabase(array $data, mixed $reference): array
    {
        $environments = $data['environments'] ?? null;
        if (!is_array($environments)) {
            throw new InvalidArgumentException('Test environments must be an array.');
        }
        $production = $environments['production'] ?? null;
        if (!is_array($production)) {
            throw new InvalidArgumentException('Test production environment must be an array.');
        }

        $production['database'] = $reference;
        $environments['production'] = $production;
        $data['environments'] = $environments;
        return $data;
    }

    /** @return array<string, mixed> */
    private static function blueprintData(): array
    {
        return [
            'version' => 1,
            'organization' => 'acme',
            'application' => [
                'name' => 'example',
                'region' => 'eu-central-1',
                'source' => ['provider' => 'github', 'repository' => 'acme/example'],
            ],
            'environments' => ['production' => ['branch' => 'main']],
        ];
    }

    /** @return array<string, mixed> */
    private static function mysqlCluster(): array
    {
        return [
            'type' => 'laravel_mysql_8',
            'region' => 'eu-central-1',
            'config' => [
                'size' => 'db-flex.m-1vcpu-512mb',
                'storage' => 5,
                'retention_days' => 1,
                'uses_scheduled_snapshots' => false,
                'is_public' => false,
            ],
            'databases' => ['application' => []],
        ];
    }

    /** @return array<string, mixed> */
    private static function neonCluster(string $type): array
    {
        return [
            'type' => $type,
            'region' => 'eu-central-1',
            'config' => [
                'cu_min' => 0.25,
                'cu_max' => 1,
                'suspend_seconds' => 300,
                'retention_days' => 7,
            ],
            'databases' => ['application' => []],
        ];
    }

    /** @param array<string, mixed> $data */
    private static function assertValidationError(
        array $data,
        string $path,
        ValidationErrorCode $code,
    ): void {
        $errors = iterator_to_array((new BlueprintValidator())->validate($data), false);
        $matchingError = null;
        foreach ($errors as $error) {
            if ($error->path === $path && $error->code === $code) {
                $matchingError = $error;
                break;
            }
        }

        self::assertNotNull(
            $matchingError,
            sprintf('Expected validation error %s at %s.', $code->value, $path),
        );
    }
}
