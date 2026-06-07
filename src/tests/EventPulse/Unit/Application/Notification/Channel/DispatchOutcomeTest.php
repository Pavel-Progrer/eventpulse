<?php

declare(strict_types=1);

namespace EventPulse\Tests\Unit\Application\Notification\Channel;

use EventPulse\Application\Notification\Channel\DispatchOutcome;
use EventPulse\Domain\Notification\Enum\FailureClassification;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour: a DispatchOutcome is a discriminated record. The success and
 * failure shapes are mutually exclusive and both carry the data the
 * application layer needs to either close out an attempt or hand a reason
 * to `Notification::recordFailure()`.
 */
#[CoversClass(DispatchOutcome::class)]
final class DispatchOutcomeTest extends TestCase
{
    #[Test]
    public function success_outcome_has_no_failure_data(): void
    {
        $outcome = DispatchOutcome::success();

        self::assertTrue($outcome->succeeded);
        self::assertNull($outcome->classification);
        self::assertNull($outcome->reason);
        self::assertNull($outcome->providerMessageId);
    }

    #[Test]
    public function success_outcome_can_carry_a_provider_message_id(): void
    {
        $outcome = DispatchOutcome::success(providerMessageId: 'ses-msg-12345');

        self::assertTrue($outcome->succeeded);
        self::assertSame('ses-msg-12345', $outcome->providerMessageId);
    }

    #[Test]
    public function failure_outcome_carries_classification_and_reason(): void
    {
        $outcome = DispatchOutcome::failure(
            classification: FailureClassification::Transient,
            reason: 'connection timeout after 30s',
        );

        self::assertFalse($outcome->succeeded);
        self::assertSame(FailureClassification::Transient, $outcome->classification);
        self::assertSame('connection timeout after 30s', $outcome->reason);
        self::assertNull($outcome->providerMessageId);
    }

    #[Test]
    public function failure_with_empty_reason_is_rejected(): void
    {
        // Why this matters: an empty reason silently degrades log triage
        // and DLQ usefulness. Catch the bug at the boundary.
        $this->expectException(InvalidArgumentException::class);

        DispatchOutcome::failure(
            classification: FailureClassification::Permanent,
            reason: '',
        );
    }

    #[Test]
    public function failure_outcomes_can_carry_each_classification(): void
    {
        foreach (FailureClassification::cases() as $classification) {
            $outcome = DispatchOutcome::failure($classification, 'a reason');

            self::assertFalse($outcome->succeeded);
            self::assertSame($classification, $outcome->classification);
        }
    }
}
