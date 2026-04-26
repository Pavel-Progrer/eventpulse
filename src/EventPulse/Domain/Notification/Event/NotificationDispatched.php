<?php

declare(strict_types=1);

namespace EventPulse\Domain\Notification\Event;

use DateTimeImmutable;
use EventPulse\Domain\DomainEvent;
use EventPulse\Domain\Notification\ValueObject\AttemptNumber;
use EventPulse\Domain\Notification\ValueObject\CorrelationId;
use EventPulse\Domain\Notification\ValueObject\NotificationId;

/**
 * An attempt succeeded — the external delivery mechanism accepted the
 * notification (domain.md §2 "dispatch", §6.1).
 *
 * Raised exactly once per notification, at the first (and only) successful
 * attempt. Terminal: a dispatched notification raises no further events.
 *
 * "Dispatched" means accepted by the external mechanism (SMTP 250, HTTP 2xx,
 * SMS provider ACK) — not that the end recipient has received it. That
 * distinction is fundamental to the domain; see domain.md §2.
 */
final class NotificationDispatched extends DomainEvent
{
    public function __construct(
        private readonly NotificationId $notificationId,
        private readonly AttemptNumber $succeededOnAttempt,
        DateTimeImmutable $occurredAt,
        CorrelationId $correlationId,
    ) {
        parent::__construct($occurredAt, $correlationId);
    }

    public function notificationId(): NotificationId
    {
        return $this->notificationId;
    }

    public function succeededOnAttempt(): AttemptNumber
    {
        return $this->succeededOnAttempt;
    }
}