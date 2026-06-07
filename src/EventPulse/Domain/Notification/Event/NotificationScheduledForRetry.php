<?php

declare(strict_types=1);

namespace EventPulse\Domain\Notification\Event;

use DateTimeImmutable;
use EventPulse\Domain\DomainEvent;
use EventPulse\Domain\Notification\ValueObject\AttemptNumber;
use EventPulse\Domain\Notification\ValueObject\CorrelationId;
use EventPulse\Domain\Notification\ValueObject\NotificationId;

/**
 * After a transient failure, the system has scheduled a retry (domain.md §6.1).
 *
 * Distinct from NotificationDispatchFailed because the decision to retry is
 * a domain decision in its own right — not every failure leads to a retry,
 * and the retry delay is a domain-layer calculation (backoff + jitter). This
 * event records both the decision and the delay so that observers can reason
 * about expected queue latency without digging into infrastructure logs.
 *
 * `retryAfter` is the absolute timestamp at which the next attempt should be
 * made. The queue infrastructure uses this to schedule the job; the domain
 * exposes it as a fact without coupling to any specific queue mechanism.
 */
final class NotificationScheduledForRetry extends DomainEvent
{
    public function __construct(
        private readonly NotificationId $notificationId,
        private readonly AttemptNumber $failedAttemptNumber,
        private readonly AttemptNumber $nextAttemptNumber,
        private readonly DateTimeImmutable $retryAfter,
        DateTimeImmutable $occurredAt,
        CorrelationId $correlationId,
    ) {
        parent::__construct($occurredAt, $correlationId);
    }

    public function notificationId(): NotificationId
    {
        return $this->notificationId;
    }

    public function failedAttemptNumber(): AttemptNumber
    {
        return $this->failedAttemptNumber;
    }

    public function nextAttemptNumber(): AttemptNumber
    {
        return $this->nextAttemptNumber;
    }

    public function retryAfter(): DateTimeImmutable
    {
        return $this->retryAfter;
    }
}
