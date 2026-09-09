<?php

declare(strict_types=1);

namespace LaravelCloudBlueprint\Tests\Unit\Apply;

use InvalidArgumentException;
use LaravelCloudBlueprint\Apply\ApplyOutcome;
use LaravelCloudBlueprint\Apply\ApplyOutcomeOperation;
use LaravelCloudBlueprint\Apply\ApplyResourceOutcome;
use LaravelCloudBlueprint\Planning\ResourceAddress;
use LaravelCloudBlueprint\Planning\ResourceType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApplyResourceOutcomeTest extends TestCase
{
    public function testOutcomeVocabularyIsStable(): void
    {
        self::assertSame([
            'created',
            'updated',
            'unchanged',
            'delete_confirmed',
            'already_absent',
            'refused',
            'conflict',
            'uncertain',
            'postcondition_failed',
            'state_checkpoint_failed',
            'failed',
        ], array_map(static fn (ApplyOutcome $outcome): string => $outcome->value, ApplyOutcome::cases()));
    }

    /** @return iterable<string, array{ApplyOutcomeOperation, ApplyOutcome}> */
    public static function validOperationOutcomes(): iterable
    {
        yield 'created' => [ApplyOutcomeOperation::CREATED, ApplyOutcome::CREATED];
        yield 'updated' => [ApplyOutcomeOperation::UPDATED, ApplyOutcome::UPDATED];
        yield 'unchanged' => [ApplyOutcomeOperation::UNCHANGED, ApplyOutcome::UNCHANGED];
        yield 'delete confirmed' => [ApplyOutcomeOperation::DELETED, ApplyOutcome::DELETE_CONFIRMED];
        yield 'already absent' => [ApplyOutcomeOperation::DELETED, ApplyOutcome::ALREADY_ABSENT];
        yield 'refused' => [ApplyOutcomeOperation::FAILED, ApplyOutcome::REFUSED];
        yield 'conflict' => [ApplyOutcomeOperation::FAILED, ApplyOutcome::CONFLICT];
        yield 'uncertain' => [ApplyOutcomeOperation::FAILED, ApplyOutcome::UNCERTAIN];
        yield 'postcondition failed' => [ApplyOutcomeOperation::FAILED, ApplyOutcome::POSTCONDITION_FAILED];
        yield 'state checkpoint failed' => [ApplyOutcomeOperation::FAILED, ApplyOutcome::STATE_CHECKPOINT_FAILED];
        yield 'ordinary failed' => [ApplyOutcomeOperation::FAILED, ApplyOutcome::FAILED];
    }

    #[DataProvider('validOperationOutcomes')]
    public function testOperationOutcomePairsAreExplicitAndStable(
        ApplyOutcomeOperation $operation,
        ApplyOutcome $outcome,
    ): void {
        $result = new ApplyResourceOutcome(self::address(), $operation, $outcome);

        self::assertSame($outcome, $result->outcome);
        self::assertMatchesRegularExpression('/^[a-z_]+$/', $result->outcome->value);
    }

    /** @return iterable<string, array{ApplyOutcomeOperation, ApplyOutcome}> */
    public static function invalidOperationOutcomes(): iterable
    {
        yield 'success with failure classification' => [ApplyOutcomeOperation::CREATED, ApplyOutcome::CONFLICT];
        yield 'failure with success classification' => [ApplyOutcomeOperation::FAILED, ApplyOutcome::CREATED];
        yield 'delete with generic success' => [ApplyOutcomeOperation::DELETED, ApplyOutcome::UPDATED];
    }

    #[DataProvider('invalidOperationOutcomes')]
    public function testInvalidOperationOutcomePairsAreRejected(
        ApplyOutcomeOperation $operation,
        ApplyOutcome $outcome,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        new ApplyResourceOutcome(self::address(), $operation, $outcome);
    }

    public function testMessageWordingDoesNotDetermineOutcome(): void
    {
        $first = new ApplyResourceOutcome(
            self::address(),
            ApplyOutcomeOperation::FAILED,
            ApplyOutcome::REFUSED,
            'First explanation.',
        );
        $second = new ApplyResourceOutcome(
            self::address(),
            ApplyOutcomeOperation::FAILED,
            ApplyOutcome::REFUSED,
            'Completely different wording.',
        );

        self::assertNotSame($first->message, $second->message);
        self::assertSame($first->outcome, $second->outcome);
    }

    private static function address(): ResourceAddress
    {
        return new ResourceAddress(ResourceType::APPLICATION, 'example');
    }
}
