<?php

declare(strict_types=1);

namespace EventPulse\Tests\Unit\Infrastructure\Logging;

use DateTimeImmutable;
use EventPulse\Domain\DomainEvent;
use EventPulse\Domain\Notification\Enum\Channel;
use EventPulse\Domain\Notification\Enum\FailureClassification;
use EventPulse\Domain\Notification\Enum\Priority;
use EventPulse\Domain\Notification\Event\NotificationDeadLettered;
use EventPulse\Domain\Notification\Event\NotificationDispatchAttempted;
use EventPulse\Domain\Notification\Event\NotificationDispatched;
use EventPulse\Domain\Notification\Event\NotificationDispatchFailed;
use EventPulse\Domain\Notification\Event\NotificationReplayed;
use EventPulse\Domain\Notification\Event\NotificationRequested;
use EventPulse\Domain\Notification\Event\NotificationScheduledForRetry;
use EventPulse\Domain\Notification\ValueObject\AttemptNumber;
use EventPulse\Domain\Notification\ValueObject\CorrelationId;
use EventPulse\Domain\Notification\ValueObject\EmailRecipient;
use EventPulse\Domain\Notification\ValueObject\IdempotencyKey;
use EventPulse\Domain\Notification\ValueObject\NotificationId;
use EventPulse\Infrastructure\Logging\StructuredLogDomainEventDispatcher;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Integration\Notification\Channel\Doubles\RecordingLogger;

/**
 * Coverage for the structured-log domain event dispatcher.
 *
 * Two distinct concerns are tested:
 *
 *   1. **Per-event rendering.** Each domain event has a single render
 *      branch; the test pins down both the log level and the keys we
 *      expect to find in the JSON context for that event. If a future
 *      change to the dispatcher (or to the events themselves) drops a
 *      field operators rely on, the test fails.
 *
 *   2. **Cross-cutting fields.** Every record carries `event`,
 *      `correlation_id`, and `occurred_at`. These are the anchor
 *      fields for log-correlation queries; verifying them once on a
 *      representative event is enough.
 *
 * The dispatcher's `default => throw` arm is also tested. The test
 * builds a dummy DomainEvent subclass and asserts that dispatching it
 * raises a `LogicException`. This is the safety net that makes adding
 * a new event without updating the dispatcher impossible to ship
 * silently.
 *
 * Note on fixture UUIDs: `NotificationId::fromString` enforces RFC 4122
 * v4 format (third group starts with `4`, fourth group starts with
 * `8`/`9`/`a`/`b`). `IdempotencyKey` and `CorrelationId` are looser
 * (printable ASCII) so they could take any string, but using the same
 * v4 shape across all id-like fixtures keeps the test data uniform and
 * matches what production payloads look like.
 */
#[CoversClass(StructuredLogDomainEventDispatcher::class)]
final class StructuredLogDomainEventDispatcherTest extends TestCase
{
    // Valid UUID v4 fixtures: third group starts with 4, fourth with 8/9/a/b.
    // The earlier draft used all-1s, all-2s etc. which are valid RFC 4122
    // strings but not v4 — that triggered the InvalidNotificationInput
    // exception on every test in this file.
    private const NOTIFICATION_ID = '11111111-1111-4111-8111-111111111111';
    private const REPLAY_ID       = '33333333-3333-4333-8333-333333333333';
    private const CORRELATION_ID  = '22222222-2222-2222-2222-222222222222';
    private const IDEMPOTENCY_KEY = '44444444-4444-4444-4444-444444444444';

    private RecordingLogger $logger;
    private StructuredLogDomainEventDispatcher $dispatcher;

    private NotificationId $notificationId;
    private CorrelationId $correlationId;
    private DateTimeImmutable $occurredAt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger     = new RecordingLogger();
        $this->dispatcher = new StructuredLogDomainEventDispatcher($this->logger);

        $this->notificationId = NotificationId::fromString(self::NOTIFICATION_ID);
        $this->correlationId  = CorrelationId::fromString(self::CORRELATION_ID);
        $this->occurredAt     = new DateTimeImmutable('2026-04-27T10:00:00Z');
    }

    // -----------------------------------------------------------------------
    // Cross-cutting: every record carries the anchor fields
    // -----------------------------------------------------------------------

    #[Test]
    public function every_record_carries_event_correlation_id_and_occurred_at(): void
    {
        $this->dispatcher->dispatch($this->makeRequested());

        $record = $this->onlyRecord();

        self::assertSame('notification_requested', $record['context']['event']);
        self::assertSame($this->correlationId->toString(), $record['context']['correlation_id']);
        self::assertSame(
            $this->occurredAt->format(\DateTimeInterface::ATOM),
            $record['context']['occurred_at'],
        );
    }

    #[Test]
    public function the_message_text_equals_the_event_name(): void
    {
        // Operators filter on either the message or the `event` context
        // field; aligning them removes ambiguity.
        $this->dispatcher->dispatch($this->makeRequested());

        $record = $this->onlyRecord();

        self::assertSame($record['context']['event'], $record['message']);
    }

    // -----------------------------------------------------------------------
    // Per-event rendering — one test per event, asserting level + context shape
    // -----------------------------------------------------------------------

    #[Test]
    public function notification_requested_renders_at_info_with_request_metadata(): void
    {
        $this->dispatcher->dispatch($this->makeRequested());

        $record = $this->onlyRecord();

        self::assertSame('info', $record['level']);
        self::assertSame($this->notificationId->toString(), $record['context']['notification_id']);
        self::assertSame('email',  $record['context']['channel']);
        self::assertSame('normal', $record['context']['priority']);
        self::assertArrayHasKey('idempotency_key', $record['context']);
        self::assertArrayHasKey('recipient',       $record['context']);
    }

    #[Test]
    public function notification_dispatch_attempted_renders_at_info_with_attempt_number(): void
    {
        $event = new NotificationDispatchAttempted(
            notificationId: $this->notificationId,
            attemptNumber:  AttemptNumber::fromInt(1),
            occurredAt:     $this->occurredAt,
            correlationId:  $this->correlationId,
        );

        $this->dispatcher->dispatch($event);

        $record = $this->onlyRecord();
        self::assertSame('info', $record['level']);
        self::assertSame(1, $record['context']['attempt_number']);
    }

    #[Test]
    public function notification_dispatched_renders_at_info_with_succeeded_on_attempt(): void
    {
        $event = new NotificationDispatched(
            notificationId:     $this->notificationId,
            succeededOnAttempt: AttemptNumber::fromInt(2),
            occurredAt:         $this->occurredAt,
            correlationId:      $this->correlationId,
        );

        $this->dispatcher->dispatch($event);

        $record = $this->onlyRecord();
        self::assertSame('info', $record['level']);
        self::assertSame(2, $record['context']['succeeded_on_attempt']);
    }

    #[Test]
    public function notification_dispatch_failed_renders_at_warning_with_classification_and_reason(): void
    {
        $event = new NotificationDispatchFailed(
            notificationId: $this->notificationId,
            attemptNumber:  AttemptNumber::fromInt(1),
            classification: FailureClassification::Transient,
            reason:         'connection refused',
            occurredAt:     $this->occurredAt,
            correlationId:  $this->correlationId,
        );

        $this->dispatcher->dispatch($event);

        $record = $this->onlyRecord();
        self::assertSame('warning', $record['level'], 'failures retry-eligible or not surface as warnings, not errors');
        self::assertSame('transient',          $record['context']['classification']);
        self::assertSame('connection refused', $record['context']['reason']);
        self::assertSame(1,                    $record['context']['attempt_number']);
    }

    #[Test]
    public function notification_scheduled_for_retry_renders_at_info_with_retry_after(): void
    {
        $retryAfter = new DateTimeImmutable('2026-04-27T10:05:00Z');

        $event = new NotificationScheduledForRetry(
            notificationId:      $this->notificationId,
            failedAttemptNumber: AttemptNumber::fromInt(1),
            nextAttemptNumber:   AttemptNumber::fromInt(2),
            retryAfter:          $retryAfter,
            occurredAt:          $this->occurredAt,
            correlationId:       $this->correlationId,
        );

        $this->dispatcher->dispatch($event);

        $record = $this->onlyRecord();
        self::assertSame('info', $record['level']);
        self::assertSame(1, $record['context']['failed_attempt_number']);
        self::assertSame(2, $record['context']['next_attempt_number']);
        self::assertSame(
            $retryAfter->format(\DateTimeInterface::ATOM),
            $record['context']['retry_after'],
        );
    }

    #[Test]
    public function notification_dead_lettered_renders_at_error_with_total_attempts_and_reason(): void
    {
        $event = new NotificationDeadLettered(
            notificationId: $this->notificationId,
            totalAttempts:  AttemptNumber::fromInt(5),
            reason:         'permanent: destination rejected',
            occurredAt:     $this->occurredAt,
            correlationId:  $this->correlationId,
        );

        $this->dispatcher->dispatch($event);

        $record = $this->onlyRecord();
        self::assertSame('error', $record['level'], 'dead-lettering is the highest severity domain event');
        self::assertSame(5, $record['context']['total_attempts']);
        self::assertSame('permanent: destination rejected', $record['context']['reason']);
    }

    #[Test]
    public function notification_replayed_renders_at_info_with_both_ids(): void
    {
        $replayId = NotificationId::fromString(self::REPLAY_ID);

        $event = new NotificationReplayed(
            originalNotificationId: $this->notificationId,
            replayNotificationId:   $replayId,
            occurredAt:             $this->occurredAt,
            correlationId:          $this->correlationId,
        );

        $this->dispatcher->dispatch($event);

        $record = $this->onlyRecord();
        self::assertSame('info', $record['level']);
        self::assertSame($this->notificationId->toString(), $record['context']['original_notification_id']);
        self::assertSame($replayId->toString(),             $record['context']['replay_notification_id']);
    }

    // -----------------------------------------------------------------------
    // Default branch — adding an event without a render arm fails loudly
    // -----------------------------------------------------------------------

    #[Test]
    public function unhandled_event_classes_throw_a_logic_exception(): void
    {
        // Anonymous DomainEvent subclass — represents "a future event
        // that someone added without updating the dispatcher."
        $unhandled = new class ($this->occurredAt, $this->correlationId) extends DomainEvent {
            public function __construct(DateTimeImmutable $at, CorrelationId $cid)
            {
                parent::__construct($at, $cid);
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('no render branch');

        $this->dispatcher->dispatch($unhandled);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeRequested(): NotificationRequested
    {
        return new NotificationRequested(
            notificationId: $this->notificationId,
            channel:        Channel::Email,
            recipient:      EmailRecipient::fromString('alice@example.test'),
            priority:       Priority::Normal,
            idempotencyKey: IdempotencyKey::fromString(self::IDEMPOTENCY_KEY),
            occurredAt:     $this->occurredAt,
            correlationId:  $this->correlationId,
        );
    }

    /**
     * @return array{level: string, message: string, context: array<string, mixed>}
     */
    private function onlyRecord(): array
    {
        self::assertCount(1, $this->logger->records, 'expected exactly one log record');

        return $this->logger->records[0];
    }
}
