<?php

declare(strict_types=1);

namespace EventPulse\Domain\Notification\Event;

use DateTimeImmutable;
use EventPulse\Domain\DomainEvent;
use EventPulse\Domain\Notification\Enum\Channel;
use EventPulse\Domain\Notification\Enum\FailureClassification;
use EventPulse\Domain\Notification\Enum\Priority;
use EventPulse\Domain\Notification\ValueObject\AttemptNumber;
use EventPulse\Domain\Notification\ValueObject\CorrelationId;
use EventPulse\Domain\Notification\ValueObject\IdempotencyKey;
use EventPulse\Domain\Notification\ValueObject\NotificationId;
use EventPulse\Domain\Notification\ValueObject\Recipient;

/**
 * A caller submitted a notification and it was accepted: created, persisted,
 * and queued for dispatch (domain.md §6.1).
 *
 * Raised exactly once per notification, at the moment the aggregate is
 * constructed. This event is the domain's record that delivery was ever
 * requested — even if every subsequent attempt fails.
 */
final class NotificationRequested extends DomainEvent
{
    public function __construct(
        private readonly NotificationId $notificationId,
        private readonly Channel $channel,
        private readonly Recipient $recipient,
        private readonly Priority $priority,
        private readonly IdempotencyKey $idempotencyKey,
        DateTimeImmutable $occurredAt,
        CorrelationId $correlationId,
    ) {
        parent::__construct($occurredAt, $correlationId);
    }

    public function notificationId(): NotificationId
    {
        return $this->notificationId;
    }

    public function channel(): Channel
    {
        return $this->channel;
    }

    public function recipient(): Recipient
    {
        return $this->recipient;
    }

    public function priority(): Priority
    {
        return $this->priority;
    }

    public function idempotencyKey(): IdempotencyKey
    {
        return $this->idempotencyKey;
    }
}