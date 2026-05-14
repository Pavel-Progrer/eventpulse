<?php

declare(strict_types=1);

namespace EventPulse\Tests\Unit\Domain\Notification\Entity;

use DateTimeImmutable;
use EventPulse\Domain\Notification\Entity\Attempt;
use EventPulse\Domain\Notification\Enum\FailureClassification;
use EventPulse\Domain\Notification\ValueObject\AttemptNumber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Attempt::class)]
final class AttemptTest extends TestCase
{
    private DateTimeImmutable $startedAt;
    private DateTimeImmutable $completedAt;

    protected function setUp(): void
    {
        $this->startedAt   = new DateTimeImmutable('2026-04-21 10:00:00');
        $this->completedAt = new DateTimeImmutable('2026-04-21 10:00:05');
    }

    public function test_new_attempt_is_in_progress(): void
    {
        $attempt = new Attempt(AttemptNumber::first(), $this->startedAt);

        self::assertTrue($attempt->isInProgress());
        self::assertNull($attempt->completedAt());
        self::assertNull($attempt->succeeded());
        self::assertNull($attempt->failureClassification());
        self::assertNull($attempt->failureReason());
    }

    public function test_new_attempt_has_correct_number_and_start_time(): void
    {
        $number  = AttemptNumber::fromInt(3);
        $attempt = new Attempt($number, $this->startedAt);

        self::assertTrue($attempt->number()->equals($number));
        self::assertSame($this->startedAt, $attempt->startedAt());
    }

    public function test_record_success_marks_attempt_as_complete(): void
    {
        $attempt = new Attempt(AttemptNumber::first(), $this->startedAt);
        $attempt->recordSuccess($this->completedAt);

        self::assertFalse($attempt->isInProgress());
        self::assertSame($this->completedAt, $attempt->completedAt());
        self::assertTrue($attempt->succeeded());
        self::assertNull($attempt->failureClassification());
        self::assertNull($attempt->failureReason());
    }

    public function test_record_failure_marks_attempt_with_classification_and_reason(): void
    {
        $attempt = new Attempt(AttemptNumber::first(), $this->startedAt);
        $attempt->recordFailure(
            FailureClassification::Transient,
            'Connection timeout',
            $this->completedAt,
        );

        self::assertFalse($attempt->isInProgress());
        self::assertSame($this->completedAt, $attempt->completedAt());
        self::assertFalse($attempt->succeeded());
        self::assertSame(FailureClassification::Transient, $attempt->failureClassification());
        self::assertSame('Connection timeout', $attempt->failureReason());
    }

    public function test_record_success_twice_throws(): void
    {
        $attempt = new Attempt(AttemptNumber::first(), $this->startedAt);
        $attempt->recordSuccess($this->completedAt);

        $this->expectException(\LogicException::class);
        $attempt->recordSuccess($this->completedAt);
    }

    public function test_record_failure_after_success_throws(): void
    {
        $attempt = new Attempt(AttemptNumber::first(), $this->startedAt);
        $attempt->recordSuccess($this->completedAt);

        $this->expectException(\LogicException::class);
        $attempt->recordFailure(
            FailureClassification::Transient,
            'Too late',
            $this->completedAt,
        );
    }

    public function test_record_success_after_failure_throws(): void
    {
        $attempt = new Attempt(AttemptNumber::first(), $this->startedAt);
        $attempt->recordFailure(FailureClassification::Permanent, 'Bad request', $this->completedAt);

        $this->expectException(\LogicException::class);
        $attempt->recordSuccess($this->completedAt);
    }

    public function test_permanent_failure_classification_is_recorded_correctly(): void
    {
        $attempt = new Attempt(AttemptNumber::first(), $this->startedAt);
        $attempt->recordFailure(FailureClassification::Permanent, 'Bad recipient', $this->completedAt);

        self::assertSame(FailureClassification::Permanent, $attempt->failureClassification());
    }
}