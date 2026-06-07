<?php

declare(strict_types=1);

namespace EventPulse\Domain\Notification\Event;

use DateTimeImmutable;
use EventPulse\Domain\DomainEvent;
use EventPulse\Domain\Notification\ValueObject\AttemptNumber;
use EventPulse\Domain\Notification\ValueObject\CorrelationId;
use EventPulse\Domain\Notification\ValueObject\NotificationId;

/**
 * A worker began a dispatch attempt (domain.md §6.1).
 *
 * Raised once per attempt — before the outcome is known. This event matters
 * for observability: it tells you how many attempts are in-flight, and lets
 * you detect attempts that started but never produced a success or failure
 * event (worker crash, OOM, etc.).
 */
final class NotificationDispatchAttempted extends DomainEvent
{
    public function __construct(
        private readonly NotificationId $notificationId,
        private readonly AttemptNumber $attemptNumber,
        DateTimeImmutable $occurredAt,
        CorrelationId $correlationId,
    ) {
        parent::__construct($occurredAt, $correlationId);
    }

    public function notificationId(): NotificationId
    {
        return $this->notificationId;
    }

    public function attemptNumber(): AttemptNumber
    {
        return $this->attemptNumber;
    }
}
