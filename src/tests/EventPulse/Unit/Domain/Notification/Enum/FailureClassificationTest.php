<?php

declare(strict_types=1);

namespace EventPulse\Tests\Unit\Domain\Notification\Enum;

use EventPulse\Domain\Notification\Enum\FailureClassification;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FailureClassification::class)]
final class FailureClassificationTest extends TestCase
{
    public function test_only_transient_is_retry_eligible(): void
    {
        self::assertTrue(FailureClassification::Transient->isRetryEligible());
        self::assertFalse(FailureClassification::Permanent->isRetryEligible());
        self::assertFalse(FailureClassification::Unrecoverable->isRetryEligible());
    }

    public function test_cases_have_correct_string_values(): void
    {
        self::assertSame('transient', FailureClassification::Transient->value);
        self::assertSame('permanent', FailureClassification::Permanent->value);
        self::assertSame('unrecoverable', FailureClassification::Unrecoverable->value);
    }

    public function test_all_cases_return_a_boolean_for_retry_eligibility(): void
    {
        foreach (FailureClassification::cases() as $classification) {
            self::assertIsBool($classification->isRetryEligible());
        }
    }
}