<?php

declare(strict_types=1);

namespace Tests\Support\Factories;

use DateTimeImmutable;
use EventPulse\Domain\Notification\Aggregate\Notification;
use EventPulse\Domain\Notification\Enum\Channel;
use EventPulse\Domain\Notification\Enum\FailureClassification;
use EventPulse\Domain\Notification\Enum\Priority;
use EventPulse\Domain\Notification\Repository\NotificationRepository;
use EventPulse\Domain\Notification\ValueObject\CorrelationId;
use EventPulse\Domain\Notification\ValueObject\EmailRecipient;
use EventPulse\Domain\Notification\ValueObject\IdempotencyKey;
use EventPulse\Domain\Notification\ValueObject\NotificationId;
use EventPulse\Domain\Notification\ValueObject\Recipient;
use EventPulse\Domain\Notification\ValueObject\SmsRecipient;
use EventPulse\Domain\Notification\ValueObject\WebhookRecipient;
use Illuminate\Support\Str;

/**
 * Fluent builder for a dead-lettered `Notification`.
 *
 * Configures the notification's request-time fields plus the
 * dead-letter event's reason and timestamp. `save()` drives the real
 * domain transitions (request → beginAttempt → recordFailure →
 * deadLetter) and persists through the real repository, so the row
 * shapes in `notifications`, `attempts`, and `dead_letter_marks` are
 * exactly what production code would write.
 *
 * The `withReason()` setter is a small piece of test-only artistic
 * licence: the domain's `recordFailure()` derives the dead-letter
 * reason from the last failure's reason string, so to hit a specific
 * DLQ reason value (`unrecoverable_error` vs `max_retries_exceeded`)
 * we choose the failure classification accordingly:
 *
 *   - `'max_retries_exceeded'` → transient classification, max
 *     attempts equal to one (one failed attempt is enough),
 *   - `'unrecoverable_error'`  → unrecoverable classification,
 *     which dead-letters immediately.
 *
 * The dead-lettered-at timestamp is also the timestamp on the
 * underlying attempt's completion and on the notification's
 * `created_at` (we backdate the request to keep the timeline
 * coherent — a notification cannot be dead-lettered before it was
 * requested).
 */
final class DlqEntryBuilder
{
    private Channel $channel = Channel::Email;
    private Priority $priority = Priority::Normal;
    private string $reason = 'max_retries_exceeded';
    private DateTimeImmutable $deadLetteredAt;
    private ?Recipient $recipient = null;
    /** @var array<string, mixed>|null */
    private ?array $rawPayload = null;

    /**
     * Number of preceding transient failures the builder will record
     * before the final dead-lettering failure. Default 0 — one
     * attempt total, which is the simplest valid path to dead-lettered
     * for `max_retries_exceeded`.
     *
     * Higher values let a test assert against a multi-attempt history
     * — e.g. "the detail endpoint renders attempts in order with the
     * right classifications" needs at least one retry preceding the
     * final failure.
     */
    private int $precedingRetries = 0;

    public function __construct(
        private readonly NotificationRepository $repository,
        private readonly string $apiKeyId,
    ) {
        // Default to a recent fixed instant. Tests that care about
        // ordering override this; tests that don't get a deterministic
        // value to assert against if they need to.
        $this->deadLetteredAt = new DateTimeImmutable('2026-04-27T10:00:00Z');
    }

    public function withChannel(Channel $channel): self
    {
        $this->channel = $channel;

        // Reset recipient + payload to channel defaults; the
        // assertion in `Notification::request()` would otherwise
        // refuse the mismatch. Tests can still override after this
        // call by chaining `withRecipient()` / `withPayload()`.
        $this->recipient  = null;
        $this->rawPayload = null;

        return $this;
    }

    public function withPriority(Priority $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    public function withReason(string $reason): self
    {
        if (! in_array($reason, ['max_retries_exceeded', 'unrecoverable_error'], strict: true)) {
            throw new \InvalidArgumentException(sprintf(
                'Test factory cannot construct a notification with DLQ reason "%s"; '
                . 'supported values are "max_retries_exceeded" and "unrecoverable_error".',
                $reason,
            ));
        }

        $this->reason = $reason;

        return $this;
    }

    /**
     * Accepts a `DateTimeImmutable` or an ISO-8601 string; the string
     * form is the common case in feature tests where the value is a
     * test fixture, not computed.
     */
    public function deadLetteredAt(DateTimeImmutable|string $at): self
    {
        $this->deadLetteredAt = $at instanceof DateTimeImmutable
            ? $at
            : new DateTimeImmutable($at);

        return $this;
    }

    /**
     * Record `$count` transient failures before the terminal
     * dead-lettering failure. Each retry takes the attempt number
     * one higher; the final attempt is `$count + 1`. Only applies
     * to the `max_retries_exceeded` reason — `unrecoverable_error`
     * dead-letters on the first failure by definition.
     */
    public function withPrecedingRetries(int $count): self
    {
        if ($count < 0) {
            throw new \InvalidArgumentException(
                'Preceding retry count must be non-negative.'
            );
        }

        $this->precedingRetries = $count;

        return $this;
    }

    public function withRecipient(Recipient $recipient): self
    {
        $this->recipient = $recipient;

        return $this;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function withPayload(array $payload): self
    {
        $this->rawPayload = $payload;

        return $this;
    }

    /**
     * Build the aggregate, walk it through the domain transitions to
     * the dead-lettered state, persist via the real repository,
     * return the saved aggregate.
     */
    public function save(): Notification
    {
        // The earliest attempt's start time. Each preceding retry
        // occupies a 60-second window; the final attempt starts
        // 30 seconds before deadLetteredAt. We backdate the request
        // to one minute before the earliest event so the aggregate's
        // timeline is coherent (request precedes every attempt).
        $earliestAttempt = $this->precedingRetries > 0
            ? $this->deadLetteredAt->modify(
                sprintf('-%d seconds', 30 + $this->precedingRetries * 60),
            )
            : $this->deadLetteredAt->modify('-30 seconds');

        $requestedAt = $earliestAttempt->modify('-1 minute');

        $notification = Notification::request(
            id:             NotificationId::generate(),
            channel:        $this->channel,
            recipient:      $this->recipient ?? $this->defaultRecipientFor($this->channel),
            rawPayload:     $this->rawPayload ?? $this->defaultPayloadFor($this->channel),
            priority:       $this->priority,
            idempotencyKey: IdempotencyKey::fromString('idem-' . Str::uuid()->toString()),
            apiKeyId:       $this->apiKeyId,
            correlationId:  CorrelationId::generate(),
            now:            $requestedAt,
        );

        $startedAt   = $this->deadLetteredAt->modify('-30 seconds');
        $completedAt = $this->deadLetteredAt;

        match ($this->reason) {
            'max_retries_exceeded' => $this->exhaustRetryBudget($notification, $startedAt, $completedAt),
            'unrecoverable_error'  => $this->failUnrecoverably($notification, $startedAt, $completedAt),
            default                => throw new \LogicException(
                'Unreachable — withReason() guards the value.',
            ),
        };

        // The aggregate is now in dead_lettered state with its full
        // attempt history and a populated DeadLetterMark. Persist.
        $this->repository->save($notification);

        // Drain pending events. Tests don't need to react to them, and
        // leaving them attached would surprise any assertion that
        // walked the aggregate's events.
        $notification->pullPendingEvents();

        return $notification;
    }

    private function exhaustRetryBudget(
        Notification $notification,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $completedAt,
    ): void {
        // The total attempt budget is `precedingRetries + 1`. We walk
        // `precedingRetries` transient failures (each retry-eligible
        // because attemptNumber < maxAttempts), then the final
        // failure exhausts the budget and dead-letters with reason
        // "max_retries_exceeded".
        $maxAttempts = $this->precedingRetries + 1;

        // Spread the preceding retries across the timeline so each
        // attempt has distinct started/completed timestamps, then
        // place the final attempt with the caller's deadLetteredAt.
        for ($i = 0; $i < $this->precedingRetries; $i++) {
            // Each preceding retry occupies a 5-second window, well
            // before the final attempt's window.
            $offset = -30 - ($this->precedingRetries - $i) * 60; // seconds before deadLetteredAt
            $rStart = $completedAt->modify(sprintf('%+d seconds', $offset));
            $rEnd   = $rStart->modify('+5 seconds');

            $notification->beginAttempt($rStart);
            $notification->recordFailure(
                classification: FailureClassification::Transient,
                reason:         'connection refused',
                maxAttempts:    $maxAttempts,
                now:            $rEnd,
                retryAfter:     $rEnd->modify('+1 minute'),
            );
        }

        // Final attempt — exhausts the budget when this is the
        // (maxAttempts)-th attempt, dead-letters.
        $notification->beginAttempt($startedAt);
        $notification->recordFailure(
            classification: FailureClassification::Transient,
            reason:         'connection refused',
            maxAttempts:    $maxAttempts,
            now:            $completedAt,
            retryAfter:     $completedAt->modify('+1 minute'),
        );
    }

    private function failUnrecoverably(
        Notification $notification,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $completedAt,
    ): void {
        $notification->beginAttempt($startedAt);
        $notification->recordFailure(
            classification: FailureClassification::Unrecoverable,
            reason:         'destination configuration is invalid',
            // maxAttempts is irrelevant for unrecoverable — the
            // classification short-circuits to dead-letter.
            maxAttempts:    99,
            now:            $completedAt,
            retryAfter:     $completedAt->modify('+1 minute'),
        );
    }

    private function defaultRecipientFor(Channel $channel): Recipient
    {
        return match ($channel) {
            Channel::Email   => EmailRecipient::fromString('recipient@example.test'),
            Channel::Sms     => SmsRecipient::fromE164('+15555550100'),
            Channel::Webhook => WebhookRecipient::fromDestinationId(Str::uuid()->toString()),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultPayloadFor(Channel $channel): array
    {
        return match ($channel) {
            Channel::Email   => ['subject' => 'Subject line', 'text' => 'Body text.'],
            Channel::Sms     => ['body' => 'A short text.'],
            Channel::Webhook => ['event' => 'demo.event', 'data' => ['k' => 'v']],
        };
    }
}
