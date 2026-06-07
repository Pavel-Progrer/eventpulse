<?php

declare(strict_types=1);

namespace EventPulse\Tests\Unit\Domain\Notification\Aggregate;

use EventPulse\Domain\Notification\Aggregate\Notification;
use EventPulse\Domain\Notification\Enum\Channel;
use EventPulse\Domain\Notification\Enum\FailureClassification;
use EventPulse\Domain\Notification\Enum\NotificationStatus;
use EventPulse\Domain\Notification\Enum\Priority;
use EventPulse\Domain\Notification\Event\NotificationDeadLettered;
use EventPulse\Domain\Notification\Event\NotificationDispatchAttempted;
use EventPulse\Domain\Notification\Event\NotificationDispatched;
use EventPulse\Domain\Notification\Event\NotificationDispatchFailed;
use EventPulse\Domain\Notification\Event\NotificationReplayed;
use EventPulse\Domain\Notification\Event\NotificationRequested;
use EventPulse\Domain\Notification\Event\NotificationScheduledForRetry;
use EventPulse\Domain\Notification\Exception\InvalidNotificationTransitionException;
use EventPulse\Domain\Notification\Exception\NotificationNotDeadLetteredForReplayException;
use EventPulse\Domain\Notification\Exception\RecipientChannelMismatchException;
use EventPulse\Domain\Notification\ValueObject\EmailRecipient;
use EventPulse\Domain\Notification\ValueObject\NotificationId;
use EventPulse\Domain\Notification\ValueObject\SmsRecipient;
use EventPulse\Domain\Notification\ValueObject\WebhookRecipient;
use EventPulse\Tests\Unit\Domain\Notification\Support\NotificationMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test organisation mirrors the invariant list from domain.md §5.1.
 * Each section heading names the invariant under test so failures are
 * immediately traceable back to the spec.
 */
#[CoversClass(Notification::class)]
final class NotificationTest extends TestCase
{
    // ---------------------------------------------------------------------------
    // Construction — request()
    // ---------------------------------------------------------------------------

    public function test_request_creates_notification_in_queued_state(): void
    {
        $n = NotificationMother::emailNotification();

        self::assertSame(NotificationStatus::Queued, $n->status());
    }

    public function test_request_assigns_provided_id(): void
    {
        $id = NotificationId::generate();
        $n = NotificationMother::emailNotification(id: $id);

        self::assertTrue($id->equals($n->id()));
    }

    public function test_request_stores_channel(): void
    {
        $n = NotificationMother::webhookNotification();
        self::assertSame(Channel::Webhook, $n->channel());
    }

    public function test_request_stores_priority(): void
    {
        $n = NotificationMother::emailNotification(priority: Priority::High);
        self::assertSame(Priority::High, $n->priority());
    }

    public function test_request_stores_api_key_id(): void
    {
        $n = NotificationMother::emailNotification(apiKeyId: 'key-abc');
        self::assertSame('key-abc', $n->apiKeyId());
    }

    public function test_request_starts_with_no_attempts(): void
    {
        $n = NotificationMother::emailNotification();
        self::assertSame(0, $n->attemptCount());
    }

    public function test_request_starts_with_no_dead_letter_mark(): void
    {
        $n = NotificationMother::emailNotification();
        self::assertNull($n->deadLetterMark());
    }

    public function test_request_raises_notification_requested_event(): void
    {
        $n = NotificationMother::emailNotification();
        $events = $n->pullPendingEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(NotificationRequested::class, $events[0]);
    }

    public function test_requested_event_carries_correct_channel(): void
    {
        $n = NotificationMother::webhookNotification();
        $event = $n->pullPendingEvents()[0];

        self::assertInstanceOf(NotificationRequested::class, $event);
        self::assertSame(Channel::Webhook, $event->channel());
    }

    public function test_pull_pending_events_clears_the_queue(): void
    {
        $n = NotificationMother::emailNotification();
        $n->pullPendingEvents();
        self::assertEmpty($n->pullPendingEvents());
    }

    // ---------------------------------------------------------------------------
    // Invariant 5.1.9 — Recipient/channel mismatch rejected at construction
    // ---------------------------------------------------------------------------

    public function test_email_channel_with_sms_recipient_throws(): void
    {
        $this->expectException(RecipientChannelMismatchException::class);

        Notification::request(
            id: NotificationId::generate(),
            channel: Channel::Email,
            recipient: SmsRecipient::fromE164('+381641234567'),
            rawPayload: ['subject' => 'Hi', 'text' => 'There'],
            priority: Priority::Normal,
            idempotencyKey: NotificationMother::idempotencyKey(),
            apiKeyId: 'key-001',
            correlationId: NotificationMother::correlationId(),
            now: NotificationMother::now(),
        );
    }

    public function test_webhook_channel_with_email_recipient_throws(): void
    {
        $this->expectException(RecipientChannelMismatchException::class);

        Notification::request(
            id: NotificationId::generate(),
            channel: Channel::Webhook,
            recipient: EmailRecipient::fromString('user@example.com'),
            rawPayload: ['event' => 'ping'],
            priority: Priority::Normal,
            idempotencyKey: NotificationMother::idempotencyKey(),
            apiKeyId: 'key-001',
            correlationId: NotificationMother::correlationId(),
            now: NotificationMother::now(),
        );
    }

    public function test_sms_channel_with_webhook_recipient_throws(): void
    {
        $this->expectException(RecipientChannelMismatchException::class);

        Notification::request(
            id: NotificationId::generate(),
            channel: Channel::Sms,
            recipient: WebhookRecipient::fromDestinationId('a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d'),
            rawPayload: ['body' => 'Hello'],
            priority: Priority::Normal,
            idempotencyKey: NotificationMother::idempotencyKey(),
            apiKeyId: 'key-001',
            correlationId: NotificationMother::correlationId(),
            now: NotificationMother::now(),
        );
    }

    // ---------------------------------------------------------------------------
    // beginAttempt — transitions to Processing
    // ---------------------------------------------------------------------------

    public function test_begin_attempt_transitions_to_processing(): void
    {
        $n = NotificationMother::emailNotification();
        $n->beginAttempt(NotificationMother::now());

        self::assertSame(NotificationStatus::Processing, $n->status());
    }

    public function test_begin_attempt_increments_attempt_count(): void
    {
        $n = NotificationMother::emailNotification();
        $n->beginAttempt(NotificationMother::now());

        self::assertSame(1, $n->attemptCount());
    }

    public function test_begin_attempt_raises_dispatch_attempted_event(): void
    {
        $n = NotificationMother::emailNotification();
        $n->pullPendingEvents();
        $n->beginAttempt(NotificationMother::now());

        $events = $n->pullPendingEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(NotificationDispatchAttempted::class, $events[0]);
    }

    public function test_dispatch_attempted_event_carries_attempt_number_1(): void
    {
        $n = NotificationMother::emailNotification();
        $n->pullPendingEvents();
        $n->beginAttempt(NotificationMother::now());

        $event = $n->pullPendingEvents()[0];
        self::assertInstanceOf(NotificationDispatchAttempted::class, $event);
        self::assertSame(1, $event->attemptNumber()->toInt());
    }

    // ---------------------------------------------------------------------------
    // Invariant 5.1.3 — Only one attempt in progress at a time
    // ---------------------------------------------------------------------------

    public function test_begin_attempt_while_attempt_in_progress_throws(): void
    {
        $n = NotificationMother::emailNotification();
        $n->beginAttempt(NotificationMother::now());

        $this->expectException(InvalidNotificationTransitionException::class);
        $n->beginAttempt(NotificationMother::now());
    }

    // ---------------------------------------------------------------------------
    // recordSuccess — happy path
    // ---------------------------------------------------------------------------

    public function test_record_success_transitions_to_dispatched(): void
    {
        $n = NotificationMother::processingNotification();
        $n->recordSuccess(NotificationMother::now());

        self::assertSame(NotificationStatus::Dispatched, $n->status());
    }

    public function test_record_success_raises_dispatched_event(): void
    {
        $n = NotificationMother::processingNotification();
        $n->recordSuccess(NotificationMother::now());

        $events = $n->pullPendingEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(NotificationDispatched::class, $events[0]);
    }

    public function test_dispatched_event_records_which_attempt_succeeded(): void
    {
        $n = NotificationMother::processingNotification();
        $n->recordSuccess(NotificationMother::now());

        $event = $n->pullPendingEvents()[0];
        self::assertInstanceOf(NotificationDispatched::class, $event);
        self::assertSame(1, $event->succeededOnAttempt()->toInt());
    }

    // ---------------------------------------------------------------------------
    // Invariant 5.1.6 — Terminal states are terminal
    // ---------------------------------------------------------------------------

    public function test_begin_attempt_on_dispatched_notification_throws(): void
    {
        $n = NotificationMother::processingNotification();
        $n->recordSuccess(NotificationMother::now());

        $this->expectException(InvalidNotificationTransitionException::class);
        $n->beginAttempt(NotificationMother::now());
    }

    public function test_begin_attempt_on_failed_notification_throws(): void
    {
        $n = NotificationMother::emailNotification();
        $n->recordUnrecoverableFailure('Destination deleted', NotificationMother::now());

        $this->expectException(InvalidNotificationTransitionException::class);
        $n->beginAttempt(NotificationMother::now());
    }

    // ---------------------------------------------------------------------------
    // recordFailure — transient, retries remaining → re-queued
    // ---------------------------------------------------------------------------

    public function test_transient_failure_with_retries_remaining_requeues(): void
    {
        $n = NotificationMother::emailNotification();
        $n->beginAttempt(NotificationMother::now());
        $n->recordFailure(
            FailureClassification::Transient,
            'Timeout',
            maxAttempts: 3,
            now: NotificationMother::now(),
            retryAfter: NotificationMother::later(30),
        );

        self::assertSame(NotificationStatus::Queued, $n->status());
    }

    public function test_transient_failure_with_retries_remaining_raises_two_events(): void
    {
        $n = NotificationMother::emailNotification();
        $n->pullPendingEvents();
        $n->beginAttempt(NotificationMother::now());
        $n->pullPendingEvents();

        $n->recordFailure(
            FailureClassification::Transient,
            'Timeout',
            maxAttempts: 3,
            now: NotificationMother::now(),
            retryAfter: NotificationMother::later(30),
        );

        $events = $n->pullPendingEvents();
        self::assertCount(2, $events);
        self::assertInstanceOf(NotificationDispatchFailed::class, $events[0]);
        self::assertInstanceOf(NotificationScheduledForRetry::class, $events[1]);
    }

    public function test_scheduled_for_retry_event_carries_correct_attempt_numbers(): void
    {
        $n = NotificationMother::emailNotification();
        $n->pullPendingEvents();
        $n->beginAttempt(NotificationMother::now());
        $n->pullPendingEvents();

        $n->recordFailure(
            FailureClassification::Transient,
            'Timeout',
            maxAttempts: 3,
            now: NotificationMother::now(),
            retryAfter: NotificationMother::later(30),
        );

        $retryEvent = $n->pullPendingEvents()[1];
        self::assertInstanceOf(NotificationScheduledForRetry::class, $retryEvent);
        self::assertSame(1, $retryEvent->failedAttemptNumber()->toInt());
        self::assertSame(2, $retryEvent->nextAttemptNumber()->toInt());
    }

    // ---------------------------------------------------------------------------
    // recordFailure — transient, retries exhausted → dead-lettered
    // ---------------------------------------------------------------------------

    public function test_transient_failure_on_last_attempt_dead_letters(): void
    {
        $n = NotificationMother::emailNotification();
        $n->beginAttempt(NotificationMother::now());
        $n->recordFailure(
            FailureClassification::Transient,
            'Timeout',
            maxAttempts: 1,
            now: NotificationMother::now(),
            retryAfter: NotificationMother::later(30),
        );

        self::assertSame(NotificationStatus::DeadLettered, $n->status());
        self::assertNotNull($n->deadLetterMark());
    }

    public function test_dead_lettered_notification_raises_dead_lettered_event(): void
    {
        $n = NotificationMother::emailNotification();
        $n->pullPendingEvents();
        $n->beginAttempt(NotificationMother::now());
        $n->pullPendingEvents();

        $n->recordFailure(
            FailureClassification::Transient,
            'Timeout',
            maxAttempts: 1,
            now: NotificationMother::now(),
            retryAfter: NotificationMother::later(30),
        );

        $events = $n->pullPendingEvents();
        self::assertCount(2, $events);
        self::assertInstanceOf(NotificationDispatchFailed::class, $events[0]);
        self::assertInstanceOf(NotificationDeadLettered::class, $events[1]);
    }

    public function test_dead_lettered_event_carries_total_attempt_count(): void
    {
        $n = NotificationMother::deadLetteredNotification(maxAttempts: 3);

        self::assertSame(3, $n->attemptCount());
        self::assertNotNull($n->deadLetterMark());
    }

    // ---------------------------------------------------------------------------
    // recordFailure — permanent/unrecoverable → dead-letters immediately
    // ---------------------------------------------------------------------------

    public function test_permanent_failure_dead_letters_regardless_of_max_attempts(): void
    {
        $n = NotificationMother::emailNotification();
        $n->beginAttempt(NotificationMother::now());
        $n->recordFailure(
            FailureClassification::Permanent,
            'Invalid recipient',
            maxAttempts: 10,
            now: NotificationMother::now(),
            retryAfter: NotificationMother::later(30),
        );

        self::assertSame(NotificationStatus::DeadLettered, $n->status());
    }

    public function test_unrecoverable_failure_dead_letters_regardless_of_max_attempts(): void
    {
        $n = NotificationMother::emailNotification();
        $n->beginAttempt(NotificationMother::now());
        $n->recordFailure(
            FailureClassification::Unrecoverable,
            'Destination deleted',
            maxAttempts: 10,
            now: NotificationMother::now(),
            retryAfter: NotificationMother::later(30),
        );

        self::assertSame(NotificationStatus::DeadLettered, $n->status());
    }

    public function test_dispatch_failed_event_carries_failure_classification(): void
    {
        $n = NotificationMother::emailNotification();
        $n->pullPendingEvents();
        $n->beginAttempt(NotificationMother::now());
        $n->pullPendingEvents();

        $n->recordFailure(
            FailureClassification::Permanent,
            'Bad address',
            maxAttempts: 3,
            now: NotificationMother::now(),
            retryAfter: NotificationMother::later(30),
        );

        $failedEvent = $n->pullPendingEvents()[0];
        self::assertInstanceOf(NotificationDispatchFailed::class, $failedEvent);
        self::assertSame(FailureClassification::Permanent, $failedEvent->classification());
        self::assertSame('Bad address', $failedEvent->reason());
    }

    // ---------------------------------------------------------------------------
    // Invariant 5.1.5 — Dead-lettering requires at least one failed attempt
    // ---------------------------------------------------------------------------

    public function test_dead_lettered_notification_has_at_least_one_failed_attempt(): void
    {
        $n = NotificationMother::deadLetteredNotification(maxAttempts: 1);

        $failedAttempts = array_filter(
            $n->attempts(),
            fn ($a) => $a->succeeded() === false,
        );
        self::assertGreaterThanOrEqual(1, count($failedAttempts));
    }

    // ---------------------------------------------------------------------------
    // Invariant 5.1.2 — Attempt numbers are contiguous from 1
    // ---------------------------------------------------------------------------

    public function test_attempt_numbers_are_contiguous_after_multiple_retries(): void
    {
        $n = NotificationMother::emailNotification();
        $maxAttempts = 3;

        for ($i = 1; $i <= $maxAttempts; $i++) {
            $n->beginAttempt(NotificationMother::now());
            if ($i < $maxAttempts) {
                $n->recordFailure(
                    FailureClassification::Transient,
                    'Timeout',
                    $maxAttempts,
                    NotificationMother::now(),
                    NotificationMother::later(30),
                );
            }
        }

        $attempts = $n->attempts();
        self::assertSame($maxAttempts, count($attempts));

        for ($i = 1; $i <= $maxAttempts; $i++) {
            self::assertArrayHasKey($i, $attempts);
            self::assertSame($i, $attempts[$i]->number()->toInt());
        }
    }

    // ---------------------------------------------------------------------------
    // recordUnrecoverableFailure — the `failed` terminal state
    // ---------------------------------------------------------------------------

    public function test_unrecoverable_failure_transitions_to_failed(): void
    {
        $n = NotificationMother::emailNotification();
        $n->recordUnrecoverableFailure('Destination deleted', NotificationMother::now());

        self::assertSame(NotificationStatus::Failed, $n->status());
    }

    public function test_unrecoverable_failure_raises_dispatch_failed_event(): void
    {
        $n = NotificationMother::emailNotification();
        $n->pullPendingEvents();
        $n->recordUnrecoverableFailure('Destination deleted', NotificationMother::now());

        $events = $n->pullPendingEvents();
        self::assertCount(1, $events);

        $event = $events[0];
        self::assertInstanceOf(NotificationDispatchFailed::class, $event);
        self::assertSame(FailureClassification::Unrecoverable, $event->classification());
    }

    public function test_unrecoverable_failure_on_already_dispatched_throws(): void
    {
        $n = NotificationMother::processingNotification();
        $n->recordSuccess(NotificationMother::now());

        $this->expectException(InvalidNotificationTransitionException::class);
        $n->recordUnrecoverableFailure('Too late', NotificationMother::now());
    }

    // ---------------------------------------------------------------------------
    // recordReplay — invariant 5.1.7
    // ---------------------------------------------------------------------------

    public function test_replay_on_dead_lettered_notification_records_replay_id(): void
    {
        $n = NotificationMother::deadLetteredNotification();
        $replayId = NotificationId::generate();
        $n->recordReplay($replayId, NotificationMother::now());

        self::assertNotNull($n->deadLetterMark());
        self::assertTrue($replayId->equals($n->deadLetterMark()->replayNotificationId()));
    }

    public function test_replay_keeps_original_in_dead_lettered_state(): void
    {
        $n = NotificationMother::deadLetteredNotification();
        $n->recordReplay(NotificationId::generate(), NotificationMother::now());

        self::assertSame(NotificationStatus::DeadLettered, $n->status());
    }

    public function test_replay_raises_notification_replayed_event(): void
    {
        $n = NotificationMother::deadLetteredNotification();
        $replayId = NotificationId::generate();
        $n->recordReplay($replayId, NotificationMother::now());

        $events = $n->pullPendingEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(NotificationReplayed::class, $events[0]);
    }

    public function test_replayed_event_carries_both_ids(): void
    {
        $original = NotificationMother::deadLetteredNotification();
        $originalId = $original->id();
        $replayId = NotificationId::generate();

        $original->recordReplay($replayId, NotificationMother::now());

        $event = $original->pullPendingEvents()[0];
        self::assertInstanceOf(NotificationReplayed::class, $event);
        self::assertTrue($originalId->equals($event->originalNotificationId()));
        self::assertTrue($replayId->equals($event->replayNotificationId()));
    }

    public function test_replay_on_queued_notification_throws(): void
    {
        $n = NotificationMother::emailNotification();

        $this->expectException(NotificationNotDeadLetteredForReplayException::class);
        $n->recordReplay(NotificationId::generate(), NotificationMother::now());
    }

    public function test_replay_on_dispatched_notification_throws(): void
    {
        $n = NotificationMother::processingNotification();
        $n->recordSuccess(NotificationMother::now());

        $this->expectException(NotificationNotDeadLetteredForReplayException::class);
        $n->recordReplay(NotificationId::generate(), NotificationMother::now());
    }

    // ---------------------------------------------------------------------------
    // reconstitute() — no events raised, state restored correctly
    // ---------------------------------------------------------------------------

    public function test_reconstitute_does_not_raise_events(): void
    {
        $original = NotificationMother::emailNotification();

        $reconstituted = Notification::reconstitute(
            id: $original->id(),
            channel: $original->channel(),
            recipient: $original->recipient(),
            payload: $original->payload(),
            priority: $original->priority(),
            idempotencyKey: $original->idempotencyKey(),
            apiKeyId: $original->apiKeyId(),
            createdAt: $original->createdAt(),
            status: NotificationStatus::Queued,
            correlationId: $original->correlationId(),
            attempts: [],
            deadLetterMark: null,
            replayOf: null,
        );

        self::assertEmpty($reconstituted->pullPendingEvents());
    }

    public function test_reconstitute_restores_all_properties(): void
    {
        $original = NotificationMother::emailNotification();

        $reconstituted = Notification::reconstitute(
            id: $original->id(),
            channel: $original->channel(),
            recipient: $original->recipient(),
            payload: $original->payload(),
            priority: $original->priority(),
            idempotencyKey: $original->idempotencyKey(),
            apiKeyId: $original->apiKeyId(),
            createdAt: $original->createdAt(),
            status: NotificationStatus::Queued,
            correlationId: $original->correlationId(),
            attempts: [],
            deadLetterMark: null,
            replayOf: null,
        );

        self::assertTrue($original->id()->equals($reconstituted->id()));
        self::assertSame($original->channel(), $reconstituted->channel());
        self::assertSame($original->apiKeyId(), $reconstituted->apiKeyId());
        self::assertSame(NotificationStatus::Queued, $reconstituted->status());
    }

    // ---------------------------------------------------------------------------
    // replayOf reference
    // ---------------------------------------------------------------------------

    public function test_replay_notification_carries_original_id_reference(): void
    {
        $originalId = NotificationId::generate();

        $replay = Notification::request(
            id: NotificationId::generate(),
            channel: Channel::Email,
            recipient: EmailRecipient::fromString('user@example.com'),
            rawPayload: ['subject' => 'Retry', 'text' => 'Please retry'],
            priority: Priority::Normal,
            idempotencyKey: NotificationMother::idempotencyKey('replay-key-001'),
            apiKeyId: 'key-001',
            correlationId: NotificationMother::correlationId(),
            now: NotificationMother::now(),
            replayOf: $originalId,
        );

        self::assertNotNull($replay->replayOf());
        self::assertTrue($originalId->equals($replay->replayOf()));
    }

    public function test_normal_notification_has_no_replay_of(): void
    {
        $n = NotificationMother::emailNotification();
        self::assertNull($n->replayOf());
    }

    // ---------------------------------------------------------------------------
    // Full lifecycle smoke tests
    // ---------------------------------------------------------------------------

    public function test_full_lifecycle_success_after_one_retry(): void
    {
        $n = NotificationMother::emailNotification();

        $n->beginAttempt(NotificationMother::now());
        self::assertSame(NotificationStatus::Processing, $n->status());
        self::assertSame(1, $n->attemptCount());

        $n->recordFailure(
            FailureClassification::Transient,
            'Timeout',
            maxAttempts: 3,
            now: NotificationMother::now(),
            retryAfter: NotificationMother::later(30),
        );
        self::assertSame(NotificationStatus::Queued, $n->status());

        $n->beginAttempt(NotificationMother::now());
        self::assertSame(NotificationStatus::Processing, $n->status());
        self::assertSame(2, $n->attemptCount());

        $n->recordSuccess(NotificationMother::now());
        self::assertSame(NotificationStatus::Dispatched, $n->status());

        $attempts = $n->attempts();
        self::assertSame(1, $attempts[1]->number()->toInt());
        self::assertSame(2, $attempts[2]->number()->toInt());
        self::assertFalse($attempts[1]->succeeded());
        self::assertTrue($attempts[2]->succeeded());
    }

    public function test_full_lifecycle_dead_lettered_after_exhausting_all_attempts(): void
    {
        $n = NotificationMother::emailNotification();
        $maxAttempts = 3;

        for ($i = 0; $i < $maxAttempts; $i++) {
            $n->beginAttempt(NotificationMother::now());
            $n->recordFailure(
                FailureClassification::Transient,
                'Timeout',
                $maxAttempts,
                NotificationMother::now(),
                NotificationMother::later(30),
            );
        }

        self::assertSame(NotificationStatus::DeadLettered, $n->status());
        self::assertSame($maxAttempts, $n->attemptCount());
        self::assertNotNull($n->deadLetterMark());
    }
}
