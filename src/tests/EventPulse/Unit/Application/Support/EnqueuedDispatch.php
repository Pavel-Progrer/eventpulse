<?php

declare(strict_types=1);

namespace EventPulse\Tests\Unit\Application\Support;

use DateTimeImmutable;
use EventPulse\Domain\Notification\Enum\Priority;
use EventPulse\Domain\Notification\ValueObject\CorrelationId;
use EventPulse\Domain\Notification\ValueObject\NotificationId;

/**
 * Read-only record of a single `NotificationDispatchQueue::enqueue()` call,
 * as captured by `InMemoryNotificationDispatchQueue`. Test-only data type;
 * not part of the production code path.
 *
 * `availableAt` is null when the enqueue was for immediate dispatch (the
 * HTTP submission path) and non-null when it carried a retry delay (the
 * worker re-enqueueing after a transient failure — Day 6).
 */
final readonly class EnqueuedDispatch
{
    public function __construct(
        public NotificationId $notificationId,
        public CorrelationId $correlationId,
        public Priority $priority,
        public ?DateTimeImmutable $availableAt = null,
    ) {}
}
