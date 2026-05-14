<?php

declare(strict_types=1);

namespace Tests\Integration\Notification\Queue;

use App\Jobs\DispatchNotificationJob;
use DateTimeImmutable;
use DateTimeZone;
use EventPulse\Domain\Notification\Enum\Priority;
use EventPulse\Domain\Notification\ValueObject\CorrelationId;
use EventPulse\Domain\Notification\ValueObject\NotificationId;
use EventPulse\Infrastructure\Notification\Queue\LaravelNotificationDispatchQueue;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Behaviour: the queue adapter dispatches `DispatchNotificationJob` to the
 * priority-mapped queue and, when `availableAt` is supplied, applies it
 * as the job's queue delay so the worker does not pick it up early.
 *
 * The priority→queue routing has unit-level coverage on the adapter's
 * `match` already; this test focuses on the Day 6 addition (delay
 * propagation) plus a smoke test that the priority routing still works.
 */
#[CoversClass(LaravelNotificationDispatchQueue::class)]
final class LaravelNotificationDispatchQueueTest extends TestCase
{
    #[Test]
    public function enqueue_without_available_at_dispatches_immediately_to_priority_queue(): void
    {
        Bus::fake();

        $adapter = new LaravelNotificationDispatchQueue();
        $id      = NotificationId::generate();
        $cid     = CorrelationId::generate();

        $adapter->enqueue($id, $cid, Priority::High);

        Bus::assertDispatched(
            DispatchNotificationJob::class,
            function (DispatchNotificationJob $job) use ($id, $cid): bool {
                return $job->notificationId === $id->toString()
                    && $job->correlationId === $cid->toString()
                    && $job->queue === 'notifications-high'
                    && $job->delay === null;
            },
        );
    }

    #[Test]
    public function enqueue_with_available_at_propagates_the_delay(): void
    {
        Bus::fake();

        $adapter     = new LaravelNotificationDispatchQueue();
        $availableAt = new DateTimeImmutable('2026-04-25T10:01:30Z', new DateTimeZone('UTC'));

        $adapter->enqueue(
            notificationId: NotificationId::generate(),
            correlationId:  CorrelationId::generate(),
            priority:       Priority::Normal,
            availableAt:    $availableAt,
        );

        Bus::assertDispatched(
            DispatchNotificationJob::class,
            function (DispatchNotificationJob $job) use ($availableAt): bool {
                // Laravel stores the delay as a `DateTimeInterface` when
                // an absolute time is provided. We assert on the
                // timestamp rather than the object identity: the
                // adapter is allowed to convert between representations,
                // but the *moment* must be preserved exactly.
                if (! $job->delay instanceof \DateTimeInterface) {
                    return false;
                }

                return $job->delay->getTimestamp() === $availableAt->getTimestamp()
                    && $job->queue === 'notifications-default';
            },
        );
    }

    #[Test]
    public function enqueue_routes_each_priority_to_its_queue(): void
    {
        Bus::fake();

        $adapter = new LaravelNotificationDispatchQueue();

        // One enqueue per priority. The per-queue assertions below
        // verify that each priority routed to exactly its expected
        // queue — if the routing degenerated (e.g. all three to
        // `notifications-default`), the High and Low assertions would
        // find no matching dispatched job and the test would fail.
        foreach ([Priority::High, Priority::Normal, Priority::Low] as $priority) {
            $adapter->enqueue(
                notificationId: NotificationId::generate(),
                correlationId:  CorrelationId::generate(),
                priority:       $priority,
            );
        }

        Bus::assertDispatchedTimes(DispatchNotificationJob::class, 3);

        // One job per priority on the corresponding queue.
        Bus::assertDispatched(DispatchNotificationJob::class, fn ($j) => $j->queue === 'notifications-high');
        Bus::assertDispatched(DispatchNotificationJob::class, fn ($j) => $j->queue === 'notifications-default');
        Bus::assertDispatched(DispatchNotificationJob::class, fn ($j) => $j->queue === 'notifications-low');
    }
}
