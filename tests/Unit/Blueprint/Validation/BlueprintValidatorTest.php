<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Blueprint\Validation;

use LaravelCloudBlueprint\Blueprint\Validation\BlueprintValidator;
use LaravelCloudBlueprint\Blueprint\Validation\ValidationError;
use LaravelCloudBlueprint\Blueprint\Validation\ValidationErrorCode;
use LaravelCloudBlueprint\Blueprint\Validation\ValidationResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlueprintValidatorTest extends TestCase
{
    public function testACompleteBlueprintHasNoValidationErrors(): void
    {
        $result = (new BlueprintValidator())->validate(self::data());

        self::assertTrue($result->isValid());
        self::assertCount(0, $result);
    }

    public function testItCollectsMultipleIndependentErrors(): void
    {
        $result = (new BlueprintValidator())->validate(self::data(
            organization: '', applicationName: '', branch: 123, unknownRootProperty: true,
        ));

        self::assertFalse($result->isValid());
        self::assertCount(4, $result);
        self::assertSame(
            ['unexpected', 'organization', 'application.name', 'environments.production.branch'],
            self::paths($result),
        );
    }

    public function testMissingRootRequiredFieldsAreReportedTogether(): void
    {
        $result = (new BlueprintValidator())->validate([]);

        self::assertSame(['version', 'organization', 'application', 'environments'], self::paths($result));
        foreach ($result as $error) {
            self::assertSame(ValidationErrorCode::REQUIRED, $error->code);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string, ValidationErrorCode}> */
    public static function invalidBlueprintProvider(): iterable
    {
        yield 'unknown root property' => [self::data(unknownRootProperty: true), 'unexpected', ValidationErrorCode::UNKNOWN_PROPERTY];
        yield 'unknown application property' => [self::data(unknownApplicationProperty: true), 'application.unexpected', ValidationErrorCode::UNKNOWN_PROPERTY];
        yield 'empty organization' => [self::data(organization: ''), 'organization', ValidationErrorCode::EMPTY_VALUE];
        yield 'whitespace organization' => [self::data(organization: '   '), 'organization', ValidationErrorCode::EMPTY_VALUE];
        yield 'empty application name' => [self::data(applicationName: ''), 'application.name', ValidationErrorCode::EMPTY_VALUE];
        yield 'whitespace application name' => [self::data(applicationName: " \t "), 'application.name', ValidationErrorCode::EMPTY_VALUE];
        yield 'missing region' => [self::data(omitRegion: true), 'application.region', ValidationErrorCode::REQUIRED];
        yield 'empty region' => [self::data(region: ''), 'application.region', ValidationErrorCode::EMPTY_VALUE];
        yield 'whitespace region' => [self::data(region: " \t "), 'application.region', ValidationErrorCode::EMPTY_VALUE];
        yield 'unsupported version' => [self::data(version: 2), 'version', ValidationErrorCode::UNSUPPORTED_VERSION];
        yield 'unsupported provider' => [self::data(provider: 'azure-devops'), 'application.source.provider', ValidationErrorCode::UNSUPPORTED_PROVIDER];
        yield 'missing branch' => [self::data(omitBranch: true), 'environments.production.branch', ValidationErrorCode::REQUIRED];
        yield 'whitespace branch' => [self::data(branch: '   '), 'environments.production.branch', ValidationErrorCode::EMPTY_VALUE];
        yield 'variables is not a mapping' => [self::data(variables: ['item']), 'environments.production.variables', ValidationErrorCode::INVALID_TYPE];
        yield 'both variable sources' => [self::data(variable: ['value' => 'production', 'from_env' => 'APP_ENV']), 'environments.production.variables.APP_ENV', ValidationErrorCode::INVALID_VARIABLE_SOURCE];
        yield 'neither variable source' => [self::data(variable: []), 'environments.production.variables.APP_ENV', ValidationErrorCode::INVALID_VARIABLE_SOURCE];
        yield 'non-string literal' => [self::data(variable: ['value' => false]), 'environments.production.variables.APP_ENV.value', ValidationErrorCode::INVALID_TYPE];
        yield 'empty environment reference' => [self::data(variable: ['from_env' => '']), 'environments.production.variables.APP_ENV.from_env', ValidationErrorCode::EMPTY_VALUE];
        yield 'whitespace environment reference' => [self::data(variable: ['from_env' => " \t "]), 'environments.production.variables.APP_ENV.from_env', ValidationErrorCode::EMPTY_VALUE];
        yield 'non-boolean sensitive' => [self::data(variable: ['value' => 'production', 'sensitive' => 'yes']), 'environments.production.variables.APP_ENV.sensitive', ValidationErrorCode::INVALID_TYPE];
        yield 'unknown variable property' => [self::data(variable: ['value' => 'production', 'unexpected' => true]), 'environments.production.variables.APP_ENV.unexpected', ValidationErrorCode::UNKNOWN_PROPERTY];
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('invalidBlueprintProvider')]
    public function testItReportsTheExpectedValidationError(array $data, string $path, ValidationErrorCode $code): void
    {
        $errors = iterator_to_array((new BlueprintValidator())->validate($data), false);

        self::assertCount(1, $errors);
        self::assertSame($path, $errors[0]->path);
        self::assertSame($code, $errors[0]->code);
    }

    public function testVariablesMayBeOmitted(): void
    {
        self::assertTrue((new BlueprintValidator())->validate(self::data(omitVariables: true))->isValid());
    }

    public function testEnvironmentCannotReferenceAnOmittedLogicalDatabase(): void
    {
        $data = self::data(omitVariables: true);
        $environments = $data['environments'];
        self::assertIsArray($environments);
        $production = $environments['production'];
        self::assertIsArray($production);
        $production['database'] = 'primary.application';
        $environments['production'] = $production;
        $data['environments'] = $environments;

        $errors = iterator_to_array((new BlueprintValidator())->validate($data), false);

        self::assertCount(1, $errors);
        self::assertSame('environments.production.database', $errors[0]->path);
        self::assertSame(ValidationErrorCode::INVALID_DATABASE_REFERENCE, $errors[0]->code);
        self::assertSame('Referenced Database Cluster does not exist.', $errors[0]->message);
    }

    /** @return iterable<string, array{string}> */
    public static function supportedProviderProvider(): iterable
    {
        yield 'github' => ['github'];
        yield 'gitlab' => ['gitlab'];
        yield 'bitbucket' => ['bitbucket'];
    }

    #[DataProvider('supportedProviderProvider')]
    public function testSupportedSourceProvidersAreAccepted(string $provider): void
    {
        self::assertTrue((new BlueprintValidator())->validate(self::data(provider: $provider))->isValid());
    }

    /**
     * @param array<string, mixed> $variable
     * @return array<string, mixed>
     */
    private static function data(
        mixed $version = 1,
        mixed $organization = 'acme',
        mixed $applicationName = 'example',
        mixed $region = 'eu-central-1',
        mixed $provider = 'github',
        mixed $branch = 'main',
        mixed $variables = null,
        array $variable = ['value' => 'production'],
        bool $omitBranch = false,
        bool $omitVariables = false,
        bool $omitRegion = false,
        bool $unknownRootProperty = false,
        bool $unknownApplicationProperty = false,
    ): array {
        $application = [
            'name' => $applicationName,
        ];
        if (!$omitRegion) {
            $application['region'] = $region;
        }
        $application['source'] = ['provider' => $provider, 'repository' => 'acme/example'];
        if ($unknownApplicationProperty) {
            $application['unexpected'] = true;
        }

        $environment = [];
        if (!$omitBranch) {
            $environment['branch'] = $branch;
        }
        if (!$omitVariables) {
            $environment['variables'] = $variables ?? ['APP_ENV' => $variable];
        }

        $data = [
            'version' => $version,
            'organization' => $organization,
            'application' => $application,
            'environments' => ['production' => $environment],
        ];
        if ($unknownRootProperty) {
            $data['unexpected'] = true;
        }

        return $data;
    }

    /** @return list<string> */
    private static function paths(ValidationResult $result): array
    {
        return array_values(array_map(
            static fn (ValidationError $error): string => $error->path,
            iterator_to_array($result),
        ));
    }
}
