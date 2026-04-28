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

    // ---------------------------------------------------------------------------
    // Construction — factory only, no public constructor
    // ---------------------------------------------------------------------------

    /**
     * Private constructor enforces use of the factory method.
     * The aggregate is always born in the `queued` state.
     */
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
     * This is the only public path into the aggregate for new instances.
     * Reconstruction from persistence goes through `reconstitute()`.
     *
     * Enforces:
     *  - Invariant 5.1.9 (recipient/channel match)
     *  - Invariant 5.1.10 (payload/channel match — delegated to NotificationPayload)
     *
     * Raises: NotificationRequested
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

        // NotificationPayload validates the payload shape for the channel
        // (invariant 5.1.10). An invalid payload throws here, before the
        // aggregate exists, so no partially-constructed aggregate is ever
        // in memory.
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

    /**
     * A worker claims this notification and begins a dispatch attempt.
     *
     * Enforces:
     *  - Invariant 5.1.3 (only one attempt in progress at a time)
     *  - Invariant 5.1.6 (no transitions from terminal states)
     *  - Invariant 5.1.2 (attempt numbers are contiguous)
     *
     * Raises: NotificationDispatchAttempted
     */
    public function beginAttempt(DateTimeImmutable $now): Attempt
    {
        $this->assertNotTerminal();
        $this->assertNoAttemptInProgress();

        $this->transitionTo(NotificationStatus::Processing);

        $number  = $this->nextAttemptNumber();
        $attempt = new Attempt($number, $now);

        // Indexed by integer for O(1) lookup; 1-based to match domain language.
        $this->attempts[$number->toInt()] = $attempt;

        $this->recordEvent(new NotificationDispatchAttempted(
            notificationId: $this->id,
            attemptNumber:  $number,
            occurredAt:     $now,
            correlationId:  $this->correlationId,
        ));

        return $attempt;
    }

    /**
     * Records that the current in-progress attempt succeeded.
     *
     * Raises: NotificationDispatched
     */
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
     * Records that the current in-progress attempt failed.
     *
     * If the failure is transient and retries remain, the notification returns
     * to `queued` and a retry is scheduled. Otherwise it is dead-lettered.
     *
     * Raises: NotificationDispatchFailed
     *         + NotificationScheduledForRetry (if retrying)
     *         + NotificationDeadLettered (if giving up)
     *
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

        // No retries — dead-letter.
        $this->deadLetter($reason, $now);
    }

    /**
     * Dead-letters the notification: the system gives up on delivery.
     *
     * Can also be called directly by the application layer for `processing →
     * failed` cases (dependency vanished, etc.) — in those cases the
     * notification transitions to `failed` rather than `dead_lettered`.
     * Use `recordUnrecoverableFailure()` for that path.
     *
     * Enforces invariant 5.1.5 (at least one failed attempt before DL).
     *
     * Raises: NotificationDeadLettered
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

    /**
     * Marks the notification as permanently `failed` — the unrecoverable path
     * where an attempt could not even be made (e.g., webhook destination deleted).
     *
     * Distinct from dead-lettering: `failed` means "never got to try";
     * `dead_lettered` means "tried and gave up" (domain.md §4).
     *
     * Raises: NotificationDispatchFailed (classification = Unrecoverable)
     */
    public function recordUnrecoverableFailure(string $reason, DateTimeImmutable $now): void
    {
        $this->assertNotTerminal();

        // Bypass transitionTo() intentionally: the state machine encodes
        // Processing → Failed as the normal path, but unrecoverable failure
        // can happen before any attempt begins (e.g. destination deleted
        // between submission and worker pickup). Dead-lettering uses the same
        // direct assignment pattern for the same reason.
        $this->status = NotificationStatus::Failed;

        // We still want a failed event in the log for observability.
        // AttemptNumber::fromInt(count + 1) represents the attempt that would
        // have been attempted but couldn't be started.
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
     * notification, producing a new notification with the given id.
     *
     * The original remains dead_lettered. Its DeadLetterMark gains a
     * reference to the new notification. A new Notification aggregate
     * is created separately (by the application layer) and will raise
     * NotificationRequested on its own.
     *
     * Enforces: notification must be in dead_lettered state.
     * Raises: NotificationReplayed (on this aggregate)
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

        $this->deadLetterMark?->recordReplay($replayNotificationId);

        $this->recordEvent(new NotificationReplayed(
            originalNotificationId: $this->id,
            replayNotificationId:   $replayNotificationId,
            occurredAt:             $now,
            correlationId:          $this->correlationId,
        ));
    }

    // ---------------------------------------------------------------------------
    // Domain event management
    // ---------------------------------------------------------------------------

    /**
     * Returns and clears all pending domain events.
     *
     * The application layer calls this after persisting the aggregate and
     * dispatches the events to whatever consumers exist (structured log,
     * future event bus, stats aggregator).
     *
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

    /**
     * Whether this aggregate's request data matches a fresh submission's.
     *
     * Used by the application layer (`SubmitNotificationHandler`) when an
     * `Idempotency-Key` collides with an existing notification: a match means
     * "same logical submission" → idempotent replay; a mismatch means
     * "different logical submission" → 409 conflict.
     *
     * Comparison fields are exactly those that constitute the request body
     * (per the OpenAPI `CreateNotificationRequest` schema):
     *   - channel, recipient, payload, priority.
     *
     * Out of scope on purpose:
     *   - idempotency key (we already know it matches — that is why we are
     *     comparing in the first place).
     *   - api_key_id (already matched — idempotency keys are scoped per key).
     *   - correlation id (a per-request tracing token, not part of the
     *     logical submission).
     *   - timestamps (the prior submission's `createdAt` differs from "now"
     *     by definition; comparing them would always report "conflict").
     *
     * Why a method on the aggregate rather than a comparison utility in the
     * handler: the *rule* for "what counts as the same submission" is a
     * domain concern (it follows directly from invariant 5.1.8). Centralising
     * it here means a future change to the request body shape needs to be
     * reflected in exactly one place.
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
    // Reconstitution — used by the repository to rebuild from persistence
    // ---------------------------------------------------------------------------

    /**
     * Rebuilds a Notification from its persisted state without raising events.
     *
     * This is the infrastructure entry point: the repository hydrates the
     * aggregate from the database using this method. No domain events are
     * raised because the events already happened — they are in the log.
     *
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
        $notification               = new self(
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
        $notification->attempts     = $attempts;
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

    /**
     * Applies a status transition, enforcing the state machine.
     * Dead-lettering is handled by deadLetter() directly because it
     * requires additional invariant checks.
     */
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

    /**
     * Derives the next attempt number from the existing attempts array,
     * guaranteeing contiguity (invariant 5.1.2).
     */
    private function nextAttemptNumber(): AttemptNumber
    {
        return AttemptNumber::fromInt(count($this->attempts) + 1);
    }

    /**
     * Returns the single attempt currently in progress.
     * Throws if called when no attempt is in progress — this is a programming
     * error, not a domain error.
     */
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

    /**
     * Validates that the Recipient concrete type is consistent with the Channel
     * (domain.md invariant 5.1.9). A recipient/channel mismatch is a caller
     * error caught at the application boundary, but we enforce it here too so
     * that the aggregate is self-defending regardless of how it is constructed.
     */
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