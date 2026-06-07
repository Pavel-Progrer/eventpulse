<?php

declare(strict_types=1);

namespace EventPulse\Domain\Notification\Event;

use DateTimeImmutable;
use EventPulse\Domain\DomainEvent;
use EventPulse\Domain\Notification\ValueObject\AttemptNumber;
use EventPulse\Domain\Notification\ValueObject\CorrelationId;
use EventPulse\Domain\Notification\ValueObject\NotificationId;

/**
 * The system gave up on delivering a notification (domain.md §6.1).
 *
 * Raised exactly once per notification that reaches the dead_lettered state.
 * Invariant 5.1.5 guarantees this event is never raised without at least one
 * prior NotificationDispatchFailed event for the same notification.
 *
 * `totalAttempts` is the count at the moment of dead-lettering — useful for
 * dashboards and alerting without requiring a join back to the attempts table.
 */
final class NotificationDeadLettered extends DomainEvent
{
    public function __construct(
        private readonly NotificationId $notificationId,
        private readonly AttemptNumber $totalAttempts,
        private readonly string $reason,
        DateTimeImmutable $occurredAt,
        CorrelationId $correlationId,
    ) {
        parent::__construct($occurredAt, $correlationId);
    }

    public function notificationId(): NotificationId
    {
        return $this->notificationId;
    }

    public function totalAttempts(): AttemptNumber
    {
        return $this->totalAttempts;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
