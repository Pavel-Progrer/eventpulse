<?php

declare(strict_types=1);

namespace EventPulse\Application\Notification;

use DateTimeImmutable;
use EventPulse\Domain\Notification\Enum\Priority;
use EventPulse\Domain\Notification\ValueObject\CorrelationId;
use EventPulse\Domain\Notification\ValueObject\NotificationId;

/**
 * The application-layer port for queueing a notification's asynchronous
 * dispatch. The HTTP path enqueues; a worker dequeues and runs
 * `DispatchNotificationJob` (infrastructure layer).
 *
 * Why an interface here:
 *  - The handler must remain framework-free (ADR-0003 §1). Calling
 *    `Bus::dispatch()` or `DispatchNotificationJob::dispatch()` directly
 *    would couple the application layer to Laravel's queue system.
 *  - The contract is intentionally narrow: enqueue a notification by id,
 *    with the correlation id and priority that the worker should honour.
 *    No queue mechanics (connection name, queue name, retries) are in the
 *    contract — those are infrastructure concerns the implementation
 *    chooses based on `priority`.
 *
 * Why pass only the `NotificationId` and not the full aggregate:
 *  - The notification is already persisted by the time this is called. The
 *    worker re-loads the current state through the repository, which means
 *    a retry sees the latest persisted state (number of attempts, status)
 *    rather than a snapshot from when the job was first queued. This is
 *    "send the id, not the data" — the standard pattern for queue jobs that
 *    operate on persisted entities.
 *  - A serialised aggregate would also break correlation: re-hydrating a
 *    `Notification` from JSON in a worker process bypasses
 *    `Notification::reconstitute()` and the value-object factories.
 *
 * The correlation id is passed alongside the id so the worker can attach
 * it to every log line in the dispatch flow — without re-reading the
 * notification just to find out who its caller was.
 *
 * Implementations:
 *  - `LaravelNotificationDispatchQueue` (production): wraps
 *    `DispatchNotificationJob::dispatch()` with priority-to-queue mapping.
 *  - `InMemoryNotificationDispatchQueue` (tests): records calls for
 *    assertion; never executes any actual work.
 */
interface NotificationDispatchQueue
{
    /**
     * Enqueue a previously persisted notification for asynchronous dispatch.
     *
     * Implementations must be infrastructure-idempotent at the queue level —
     * calling `enqueue()` twice with the same id may produce two queued jobs,
     * but the worker (`DispatchNotificationJob`) is responsible for handling
     * that gracefully (no double-dispatch). The application layer's
     * idempotency guarantee (one logical submission → one logical dispatch)
     * is provided by the `findByIdempotencyKey` dedup that runs *before*
     * this is called, not by this method itself.
     *
     * @param DateTimeImmutable|null $availableAt If non-null, the queue must
     *   not deliver the job to a worker before this absolute timestamp.
     *   Used by the retry path: a transient failure schedules the retry at
     *   `now + backoff_delay`, and the worker should not pick it up early.
     *   Day 6 introduces this parameter; the HTTP submission path leaves it
     *   null, meaning "available immediately."
     *
     *   Why an absolute timestamp rather than a `DateInterval` delay:
     *   the retry-after timestamp is also written into the
     *   `NotificationScheduledForRetry` domain event and (in Day 8) into
     *   structured log entries. Computing it once at the application layer
     *   and passing the same value through both paths means the queue's
     *   "available at" and the event's "retry after" are guaranteed to
     *   agree. A relative delay would be re-resolved against a different
     *   "now" inside the adapter and could drift.
     */
    public function enqueue(
        NotificationId $notificationId,
        CorrelationId $correlationId,
        Priority $priority,
        ?DateTimeImmutable $availableAt = null,
    ): void;
}
