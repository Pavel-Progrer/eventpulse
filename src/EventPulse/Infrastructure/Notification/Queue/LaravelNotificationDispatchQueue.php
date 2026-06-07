<?php

declare(strict_types=1);

namespace EventPulse\Infrastructure\Notification\Queue;

use App\Jobs\DispatchNotificationJob;
use DateTimeImmutable;
use EventPulse\Application\Notification\NotificationDispatchQueue;
use EventPulse\Domain\Notification\Enum\Priority;
use EventPulse\Domain\Notification\ValueObject\CorrelationId;
use EventPulse\Domain\Notification\ValueObject\NotificationId;

/**
 * Laravel-backed implementation of `NotificationDispatchQueue`.
 *
 * Two responsibilities:
 *  1. Translate from domain types (`NotificationId`, `CorrelationId`,
 *     `Priority`) to the primitive payload `DispatchNotificationJob` carries
 *     — string id + string correlation id. Domain VOs are not serialised
 *     across the queue boundary; Redis persists job payloads as JSON and
 *     value-object hydration on the worker side goes back through the
 *     factories.
 *  2. Route by priority to one of three queues. The mapping is in
 *     `queueFor()`; rationale lives there.
 *
 * Day 6 widens the contract: an optional `availableAt` timestamp delays
 * the job's worker pickup. This is how the retry path schedules a
 * back-off — the job is queued immediately but tagged not-before the
 * computed retry-after timestamp.
 *
 * The adapter does not set retry/backoff policy at the queue level —
 * `DispatchNotificationJob::$tries = 1` keeps Laravel's queue retry
 * disabled, and the domain decides retries via `recordFailure`. This
 * adapter only honours the *scheduling* the domain has already chosen.
 */
final class LaravelNotificationDispatchQueue implements NotificationDispatchQueue
{
    #[\Override]
    public function enqueue(
        NotificationId $notificationId,
        CorrelationId $correlationId,
        Priority $priority,
        ?DateTimeImmutable $availableAt = null,
    ): void {
        $pending = DispatchNotificationJob::dispatch(
            $notificationId->toString(),
            $correlationId->toString(),
        )->onQueue($this->queueFor($priority));

        if ($availableAt !== null) {
            // Laravel's `delay()` accepts `DateTimeInterface|DateInterval|int`.
            // Passing the absolute timestamp keeps the application's
            // computed `retry_after` and the queue's "available at" in
            // exact agreement — a relative delay would re-resolve
            // against the queue worker's wall clock, drifting from the
            // value persisted on the `NotificationScheduledForRetry`
            // event by however much the application-side computation
            // and the queue dispatch are separated in time.
            $pending->delay($availableAt);
        }
    }

    /**
     * Map domain `Priority` to a Laravel queue name.
     *
     * EventPulse uses three queues so workers can be scaled per priority and
     * a low-priority backlog cannot starve high-priority alerts. The mapping
     * lives here, in infrastructure, because queue names are a deployment
     * concept (they could become topic names, partition names, etc. in a
     * different transport) — the domain `Priority` enum stays transport-free.
     *
     * Why a `match` rather than a const array keyed by `$priority->value`:
     * `match` is exhaustive over the enum's cases. Adding a new `Priority`
     * case becomes a static-analysis error (Psalm/PHPStan) here at the exact
     * site that stops being total — the system tells us about the gap before
     * a runtime "undefined index" can. A string-keyed const array silently
     * tolerates the same gap and only fails at request time.
     *
     * The queue names themselves are part of the deployment contract: workers
     * are configured to listen on these specific names. Renaming is a
     * deployment change, not a runtime one — which is why they live in code,
     * not configuration.
     */
    private function queueFor(Priority $priority): string
    {
        return match ($priority) {
            Priority::High => 'notifications-high',
            Priority::Normal => 'notifications-default',
            Priority::Low => 'notifications-low',
        };
    }
}
