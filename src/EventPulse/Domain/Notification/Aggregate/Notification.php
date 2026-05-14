<?php

declare(strict_types=1);

namespace EventPulse\Domain\Notification\Aggregate;

use DateTimeImmutable;
use EventPulse\Domain\DomainEvent;
use EventPulse\Domain\Notification\Entity\Attempt;
use EventPulse\Domain\Notification\Entity\DeadLetterMark;
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
use EventPulse\Domain\Notification\ValueObject\AttemptNumber;
use EventPulse\Domain\Notification\ValueObject\CorrelationId;
use EventPulse\Domain\Notification\ValueObject\EmailRecipient;
use EventPulse\Domain\Notification\ValueObject\IdempotencyKey;
use EventPulse\Domain\Notification\ValueObject\NotificationId;
use EventPulse\Domain\Notification\ValueObject\NotificationPayload;
use EventPulse\Domain\Notification\ValueObject\Recipient;
use EventPulse\Domain\Notification\ValueObject\SmsRecipient;
use EventPulse\Domain\Notification\ValueObject\WebhookRecipient;

/**
 * The Notification aggregate root (domain.md §3.1).
 *
 * Owns the complete lifecycle of a single dispatch intent: from the moment
 * a caller submits a request through every attempt to deliver it, to its
 * terminal state (dispatched, failed) or dead-letter state.
 *
 * Invariants enforced here (referencing domain.md §5.1):
 *  1. Identity is immutable.
 *  2. Attempt numbers are contiguous from 1.
 *  3. Exactly one attempt is in progress at a time.
 *  4. Attempts are append-only.
 *  5. Dead-lettering requires at least one failed attempt.
 *  6. Terminal states are terminal.
 *  7. Replay is a new aggregate; the original remains dead-lettered.
 *  9. Recipient form matches channel.
 * 10. Payload shape matches channel.
 *
 * Domain events are collected internally and released by the application layer
 * after the aggregate is persisted. This avoids the problem of events being
 * published for aggregates that were never actually saved.
 *
 * Framework note: this class has zero Laravel dependencies. It imports nothing
 * from Illuminate. The application service layer owns the glue between this
 * aggregate and Laravel's queue, ORM, and event bus.
 */
final class Notification
{
    /** @var Attempt[] Indexed by attempt number (1-based). */
    private array $attempts = [];

    private ?DeadLetterMark $deadLetterMark = null;

    /** @var DomainEvent[] Pending events, released by the application layer. */
    private array $pendingEvents = [];

    /**
     * DLQ category emitted when a retry-eligible failure exhausts the
     * configured `maxAttempts`. Distinct from the per-attempt failure
     * string (`'connection refused'`, etc.), which is preserved on the
     * `Attempt` entity. Pinned by the OpenAPI `DlqReason` enum and the
     * `dead_letter_marks.reason` column's expected values.
     */
    private const DLQ_REASON_MAX_RETRIES_EXCEEDED = 'max_retries_exceeded';

    /** DLQ category emitted when a non-retry-eligible failure occurs. */
    private const DLQ_REASON_UNRECOVERABLE_ERROR = 'unrecoverable_error';

    // ---------------------------------------------------------------------------
    // Construction — factory only, no public constructor
    // ---------------------------------------------------------------------------

    private function __construct(
        private readonly NotificationId $id,
        private readonly Channel $channel,
        private readonly Recipient $recipient,
        private readonly NotificationPayload $payload,
        private readonly Priority $priority,
        private readonly IdempotencyKey $idempotencyKey,
        private readonly string $apiKeyId,
        private readonly DateTimeImmutable $createdAt,
        private NotificationStatus $status,
        private readonly CorrelationId $correlationId,
        private readonly ?NotificationId $replayOf,
    ) {}

    /**
     * Creates a new Notification from a caller's dispatch request.
     *
     * @param array<string, mixed> $rawPayload Raw payload array; validated by NotificationPayload.
     */
    public static function request(
        NotificationId $id,
        Channel $channel,
        Recipient $recipient,
        array $rawPayload,
        Priority $priority,
        IdempotencyKey $idempotencyKey,
        string $apiKeyId,
        CorrelationId $correlationId,
        DateTimeImmutable $now,
        ?NotificationId $replayOf = null,
    ): self {
        self::assertRecipientMatchesChannel($recipient, $channel);

        $payload = NotificationPayload::forChannel($rawPayload, $channel);

        $notification = new self(
            id:             $id,
            channel:        $channel,
            recipient:      $recipient,
            payload:        $payload,
            priority:       $priority,
            idempotencyKey: $idempotencyKey,
            apiKeyId:       $apiKeyId,
            createdAt:      $now,
            status:         NotificationStatus::Queued,
            correlationId:  $correlationId,
            replayOf:       $replayOf,
        );

        $notification->recordEvent(new NotificationRequested(
            notificationId: $id,
            channel:        $channel,
            recipient:      $recipient,
            priority:       $priority,
            idempotencyKey: $idempotencyKey,
            occurredAt:     $now,
            correlationId:  $correlationId,
        ));

        return $notification;
    }

    // ---------------------------------------------------------------------------
    // Lifecycle transitions
    // ---------------------------------------------------------------------------

    public function beginAttempt(DateTimeImmutable $now): Attempt
    {
        $this->assertNotTerminal();
        $this->assertNoAttemptInProgress();

        $this->transitionTo(NotificationStatus::Processing);

        $number  = $this->nextAttemptNumber();
        $attempt = new Attempt($number, $now);

        $this->attempts[$number->toInt()] = $attempt;

        $this->recordEvent(new NotificationDispatchAttempted(
            notificationId: $this->id,
            attemptNumber:  $number,
            occurredAt:     $now,
            correlationId:  $this->correlationId,
        ));

        return $attempt;
    }

    public function recordSuccess(DateTimeImmutable $now): void
    {
        $attempt = $this->currentAttempt();
        $attempt->recordSuccess($now);

        $this->transitionTo(NotificationStatus::Dispatched);

        $this->recordEvent(new NotificationDispatched(
            notificationId:    $this->id,
            succeededOnAttempt: $attempt->number(),
            occurredAt:         $now,
            correlationId:      $this->correlationId,
        ));
    }

    /**
     * @param int $maxAttempts The ceiling enforced by the channel's retry policy.
     *                         Passed in rather than hard-coded so the policy lives
     *                         in configuration, not in the domain model.
     */
    public function recordFailure(
        FailureClassification $classification,
        string $reason,
        int $maxAttempts,
        DateTimeImmutable $now,
        DateTimeImmutable $retryAfter,
    ): void {
        $attempt = $this->currentAttempt();
        $attempt->recordFailure($classification, $reason, $now);

        $this->recordEvent(new NotificationDispatchFailed(
            notificationId: $this->id,
            attemptNumber:  $attempt->number(),
            classification: $classification,
            reason:         $reason,
            occurredAt:     $now,
            correlationId:  $this->correlationId,
        ));

        $retriesRemaining = $classification->isRetryEligible()
            && $attempt->number()->toInt() < $maxAttempts;

        if ($retriesRemaining) {
            $this->transitionTo(NotificationStatus::Queued);

            $this->recordEvent(new NotificationScheduledForRetry(
                notificationId:    $this->id,
                failedAttemptNumber: $attempt->number(),
                nextAttemptNumber:   $attempt->number()->next(),
                retryAfter:          $retryAfter,
                occurredAt:          $now,
                correlationId:       $this->correlationId,
            ));

            return;
        }

        // No retries. Two distinct paths land here:
        //   - the failure is non-retry-eligible (unrecoverable),
        //   - the failure is retry-eligible but the attempt budget
        //     is exhausted.
        // The DLQ category recorded on the mark distinguishes the two,
        // because the API exposes them as filter values. The
        // per-attempt failure string is preserved on the `Attempt`
        // entity and on the dispatch-failed event.
        $dlqReason = $classification->isRetryEligible()
            ? self::DLQ_REASON_MAX_RETRIES_EXCEEDED
            : self::DLQ_REASON_UNRECOVERABLE_ERROR;

        $this->deadLetter($dlqReason, $now);
    }

    /**
     * @param string $reason The DLQ category — one of the
     *                       `DLQ_REASON_*` class constants. NOT the
     *                       free-form per-attempt failure string;
     *                       that lives on the `Attempt` entity.
     */
    private function deadLetter(string $reason, DateTimeImmutable $now): void
    {
        // Invariant 5.1.5 — dead-lettering requires at least one failed attempt.
        $failedCount = $this->countFailedAttempts();
        if ($failedCount === 0) {
            throw new InvalidNotificationTransitionException(
                'Cannot dead-letter a notification with no failed attempts (invariant 5.1.5).'
            );
        }

        $this->deadLetterMark = new DeadLetterMark($reason, $now);
        $this->status         = NotificationStatus::DeadLettered;

        $this->recordEvent(new NotificationDeadLettered(
            notificationId: $this->id,
            totalAttempts:  AttemptNumber::fromInt(count($this->attempts)),
            reason:         $reason,
            occurredAt:     $now,
            correlationId:  $this->correlationId,
        ));
    }

    public function recordUnrecoverableFailure(string $reason, DateTimeImmutable $now): void
    {
        $this->assertNotTerminal();

        $this->status = NotificationStatus::Failed;

        $this->recordEvent(new NotificationDispatchFailed(
            notificationId: $this->id,
            attemptNumber:  AttemptNumber::fromInt(count($this->attempts) + 1),
            classification: FailureClassification::Unrecoverable,
            reason:         $reason,
            occurredAt:     $now,
            correlationId:  $this->correlationId,
        ));
    }

    /**
     * Records that an operator triggered a replay of this dead-lettered
     * notification, producing a new notification with the given id at
     * the given moment.
     *
     * The original remains dead_lettered. Its DeadLetterMark gains a
     * reference to the new notification AND the replay timestamp. A new
     * Notification aggregate is created separately (by the application
     * layer) and will raise NotificationRequested on its own.
     *
     * Day 8 update: passing `$now` to `DeadLetterMark::recordReplay`
     * keeps the entity the single source of truth for "when was this
     * replayed" — previously the timestamp was inferred at the
     * persistence boundary, which was a model-truth gap (see the
     * `DeadLetterMark` docblock).
     *
     * @see domain.md invariant 5.1.7
     */
    public function recordReplay(NotificationId $replayNotificationId, DateTimeImmutable $now): void
    {
        if ($this->status !== NotificationStatus::DeadLettered) {
            throw new NotificationNotDeadLetteredForReplayException(
                sprintf(
                    'Cannot replay notification %s: status is %s, expected dead_lettered.',
                    $this->id->toString(),
                    $this->status->value,
                )
            );
        }

        $this->deadLetterMark?->recordReplay($replayNotificationId, $now);

        $this->recordEvent(new NotificationReplayed(
            originalNotificationId: $this->id,
            replayNotificationId:   $replayNotificationId,
            occurredAt:             $now,
            correlationId:          $this->correlationId,
        ));
    }

    /**
     * Returns true if a fresh submission has the same business identity as
     * this aggregate already has — same channel, same recipient, same
     * payload, same priority. The application's idempotency check uses this
     * to distinguish two cases that look the same from the wire:
     *
     *  - "the same request was retried" (return true → idempotent replay,
     *    same notification id, no second persist or enqueue),
     *  - "a different request happens to share the idempotency key"
     *    (return false → 409 IdempotencyConflictException).
     *
     * The "same submission" rule lives here on the aggregate, not in the
     * application service, because it is a property of what makes a
     * notification *the same notification*. The handler's job is to react
     * to the answer; the rule of what counts as same is a domain decision.
     *
     * Idempotency key is intentionally not in the comparison: the key is
     * how the existing aggregate was found in the first place. Comparing
     * the key against itself would always be true; including it would
     * suggest the rule is "key + business fields match" when the rule is
     * actually "given the same key, do the business fields match."
     *
     * Correlation id and api-key id are also excluded: the same submission
     * may be retried from a different request (new correlation id), and
     * cross-tenant idempotency is impossible anyway because the lookup
     * already filtered by api_key_id.
     *
     * @see EventPulse\Application\Notification\Command\SubmitNotificationHandler
     */
    public function matchesSubmission(
        Channel $channel,
        Recipient $recipient,
        NotificationPayload $payload,
        Priority $priority,
    ): bool {
        return $this->channel === $channel
            && $this->recipient->equals($recipient)
            && $this->payload->equals($payload)
            && $this->priority === $priority;
    }

    // ---------------------------------------------------------------------------
    // Domain event management
    // ---------------------------------------------------------------------------

    /**
     * @return DomainEvent[]
     */
    public function pullPendingEvents(): array
    {
        $events              = $this->pendingEvents;
        $this->pendingEvents = [];

        return $events;
    }

    // ---------------------------------------------------------------------------
    // Accessors
    // ---------------------------------------------------------------------------

    public function id(): NotificationId
    {
        return $this->id;
    }

    public function channel(): Channel
    {
        return $this->channel;
    }

    public function recipient(): Recipient
    {
        return $this->recipient;
    }

    public function payload(): NotificationPayload
    {
        return $this->payload;
    }

    public function priority(): Priority
    {
        return $this->priority;
    }

    public function idempotencyKey(): IdempotencyKey
    {
        return $this->idempotencyKey;
    }

    public function apiKeyId(): string
    {
        return $this->apiKeyId;
    }

    public function status(): NotificationStatus
    {
        return $this->status;
    }

    public function correlationId(): CorrelationId
    {
        return $this->correlationId;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function replayOf(): ?NotificationId
    {
        return $this->replayOf;
    }

    public function deadLetterMark(): ?DeadLetterMark
    {
        return $this->deadLetterMark;
    }

    /**
 * Latest `completedAt` across this notification's attempts, or `null`
 * if no attempt has completed (every attempt is in-progress, or the
 * notification has no attempts at all).
 *
 * Lives on the aggregate because it is a property of the notification's
 * own history. The DLQ list endpoint computes the same value via a
 * `MAX(attempts.completed_at)` SQL sub-select for efficiency at scale;
 * the two implementations express the same definition in different
 * layers and must stay aligned. If the definition ever changes (e.g.
 * "latest *failed* attempt" vs "latest of any kind"), update both.
 *
 * @see EloquentDeadLetteredNotificationsRepository::list  for the SQL twin.
 */
public function finalAttemptAt(): ?DateTimeImmutable
{
    $latest = null;

    foreach ($this->attempts as $attempt) {
        $completed = $attempt->completedAt();

        if ($completed === null) {
            continue;
        }

        if ($latest === null || $completed > $latest) {
            $latest = $completed;
        }
    }

    return $latest;
}

    /**
     * @return Attempt[]
     */
    public function attempts(): array
    {
        return $this->attempts;
    }

    public function attemptCount(): int
    {
        return count($this->attempts);
    }

    // ---------------------------------------------------------------------------
    // Reconstitution — used by the repository to rebuild from persistence
    // ---------------------------------------------------------------------------

    /**
     * @param Attempt[] $attempts
     */
    public static function reconstitute(
        NotificationId $id,
        Channel $channel,
        Recipient $recipient,
        NotificationPayload $payload,
        Priority $priority,
        IdempotencyKey $idempotencyKey,
        string $apiKeyId,
        DateTimeImmutable $createdAt,
        NotificationStatus $status,
        CorrelationId $correlationId,
        array $attempts,
        ?DeadLetterMark $deadLetterMark,
        ?NotificationId $replayOf,
    ): self {
        $notification                 = new self(
            id:             $id,
            channel:        $channel,
            recipient:      $recipient,
            payload:        $payload,
            priority:       $priority,
            idempotencyKey: $idempotencyKey,
            apiKeyId:       $apiKeyId,
            createdAt:      $createdAt,
            status:         $status,
            correlationId:  $correlationId,
            replayOf:       $replayOf,
        );
        $notification->attempts       = $attempts;
        $notification->deadLetterMark = $deadLetterMark;

        return $notification;
    }

    // ---------------------------------------------------------------------------
    // Private helpers
    // ---------------------------------------------------------------------------

    private function recordEvent(DomainEvent $event): void
    {
        $this->pendingEvents[] = $event;
    }

    private function transitionTo(NotificationStatus $next): void
    {
        if (!$this->status->canTransitionTo($next)) {
            throw new InvalidNotificationTransitionException(
                sprintf(
                    'Cannot transition Notification from %s to %s.',
                    $this->status->value,
                    $next->value,
                )
            );
        }

        $this->status = $next;
    }

    private function assertNotTerminal(): void
    {
        if ($this->status->isTerminal()) {
            throw new InvalidNotificationTransitionException(
                sprintf(
                    'Notification %s is in terminal state %s and cannot be transitioned.',
                    $this->id->toString(),
                    $this->status->value,
                )
            );
        }
    }

    private function assertNoAttemptInProgress(): void
    {
        foreach ($this->attempts as $attempt) {
            if ($attempt->isInProgress()) {
                throw new InvalidNotificationTransitionException(
                    sprintf(
                        'Notification %s already has an attempt in progress (invariant 5.1.3).',
                        $this->id->toString(),
                    )
                );
            }
        }
    }

    private function nextAttemptNumber(): AttemptNumber
    {
        return AttemptNumber::fromInt(count($this->attempts) + 1);
    }

    private function currentAttempt(): Attempt
    {
        foreach (array_reverse($this->attempts, preserve_keys: true) as $attempt) {
            if ($attempt->isInProgress()) {
                return $attempt;
            }
        }

        throw new \LogicException(
            sprintf('No attempt in progress for Notification %s.', $this->id->toString())
        );
    }

    private function countFailedAttempts(): int
    {
        return count(array_filter(
            $this->attempts,
            fn(Attempt $a): bool => $a->succeeded() === false,
        ));
    }

    private static function assertRecipientMatchesChannel(Recipient $recipient, Channel $channel): void
    {
        $valid = match ($channel) {
            Channel::Email   => $recipient instanceof EmailRecipient,
            Channel::Webhook => $recipient instanceof WebhookRecipient,
            Channel::Sms     => $recipient instanceof SmsRecipient,
        };

        if (!$valid) {
            throw new RecipientChannelMismatchException(
                sprintf(
                    'Channel %s requires a %s recipient; got %s.',
                    $channel->value,
                    match ($channel) {
                        Channel::Email   => EmailRecipient::class,
                        Channel::Webhook => WebhookRecipient::class,
                        Channel::Sms     => SmsRecipient::class,
                    },
                    $recipient::class,
                )
            );
        }
    }
}
