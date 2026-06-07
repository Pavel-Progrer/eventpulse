<?php

declare(strict_types=1);

namespace EventPulse\Domain\Notification\Event;

use DateTimeImmutable;
use EventPulse\Domain\DomainEvent;
use EventPulse\Domain\Notification\Enum\FailureClassification;
use EventPulse\Domain\Notification\ValueObject\AttemptNumber;
use EventPulse\Domain\Notification\ValueObject\CorrelationId;
use EventPulse\Domain\Notification\ValueObject\NotificationId;

/**
 * An attempt failed (domain.md §6.1).
 *
 * Raised once per failed attempt regardless of classification. The
 * classification is included in the payload because it drives two different
 * downstream reactions:
 *  - Transient  → expect a NotificationScheduledForRetry immediately after.
 *  - Permanent / Unrecoverable → expect a NotificationDeadLettered.
 *
 * Separating "failed" from "scheduled for retry" (domain.md §6.1) is
 * intentional: the decision to retry is itself a domain fact worth recording
 * independently of the failure that triggered it.
 */
final class NotificationDispatchFailed extends DomainEvent
{
    public function __construct(
        private readonly NotificationId $notificationId,
        private readonly AttemptNumber $attemptNumber,
        private readonly FailureClassification $classification,
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

    public function attemptNumber(): AttemptNumber
    {
        return $this->attemptNumber;
    }

    public function classification(): FailureClassification
    {
        return $this->classification;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
