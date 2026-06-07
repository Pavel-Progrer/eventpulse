<?php

declare(strict_types=1);

namespace EventPulse\Domain\Notification\Event;

use DateTimeImmutable;
use EventPulse\Domain\DomainEvent;
use EventPulse\Domain\Notification\ValueObject\CorrelationId;
use EventPulse\Domain\Notification\ValueObject\NotificationId;

/**
 * A dead-lettered notification produced a replay (domain.md §6.1).
 *
 * Raised on the *original* notification when an operator triggers a replay.
 * The replay itself is a brand-new Notification aggregate that separately
 * raises NotificationRequested with a back-reference to the original.
 *
 * These are two distinct events because they describe two distinct facts:
 *  - This event says "the original was replayed."
 *  - NotificationRequested (on the new notification) says "a new delivery
 *    attempt is now queued."
 *
 * The original notification's state remains dead_lettered — it does not
 * transition to a "replayed" state. "Replayed" is a property of the
 * DeadLetterMark (domain.md invariant 5.1.7).
 */
final class NotificationReplayed extends DomainEvent
{
    public function __construct(
        private readonly NotificationId $originalNotificationId,
        private readonly NotificationId $replayNotificationId,
        DateTimeImmutable $occurredAt,
        CorrelationId $correlationId,
    ) {
        parent::__construct($occurredAt, $correlationId);
    }

    public function originalNotificationId(): NotificationId
    {
        return $this->originalNotificationId;
    }

    public function replayNotificationId(): NotificationId
    {
        return $this->replayNotificationId;
    }
}
