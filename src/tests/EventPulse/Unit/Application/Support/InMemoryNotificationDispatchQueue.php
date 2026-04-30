<?php

declare(strict_types=1);

namespace EventPulse\Tests\Unit\Application\Support;

use DateTimeImmutable;
use EventPulse\Application\Notification\NotificationDispatchQueue;
use EventPulse\Domain\Notification\Enum\Priority;
use EventPulse\Domain\Notification\ValueObject\CorrelationId;
use EventPulse\Domain\Notification\ValueObject\NotificationId;

/**
 * Records `enqueue()` calls for unit-test assertion.
 *
 * Mirrors `InMemoryNotificationRepository`'s philosophy: a real
 * implementation of the port that records what would have happened,
 * instead of a mocking framework's expectation set. Real implementations
 * of test doubles compose with handler logic correctly even when
 * behaviour evolves.
 *
 * Each `enqueue()` call is captured as an `EnqueuedDispatch` record. Tests
 * assert against the array directly with whatever shape they need.
 */
final class InMemoryNotificationDispatchQueue implements NotificationDispatchQueue
{
    /** @var list<EnqueuedDispatch> */
    private array $enqueued = [];

    #[\Override]
    public function enqueue(
        NotificationId $notificationId,
        CorrelationId $correlationId,
        Priority $priority,
        ?DateTimeImmutable $availableAt = null,
    ): void {
        $this->enqueued[] = new EnqueuedDispatch(
            notificationId: $notificationId,
            correlationId:  $correlationId,
            priority:       $priority,
            availableAt:    $availableAt,
        );
    }

    /**
     * @return list<EnqueuedDispatch>
     */
    public function enqueued(): array
    {
        return $this->enqueued;
    }

    public function count(): int
    {
        return count($this->enqueued);
    }

    public function lastEnqueued(): ?EnqueuedDispatch
    {
        return $this->enqueued === [] ? null : $this->enqueued[array_key_last($this->enqueued)];
    }
}
