<?php

declare(strict_types=1);

namespace EventPulse\Infrastructure\Notification\Queue;

use App\Jobs\DispatchNotificationJob;
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
 * The adapter does not set retry/backoff policy — that lives on the job
 * itself (`tries`, `backoff()`), which is consistent across all enqueue
 * paths (HTTP submission, Day 8 retry-after-failure, Day 5 worker re-claim).
 */
final class LaravelNotificationDispatchQueue implements NotificationDispatchQueue
{
    #[\Override]
    public function enqueue(
        NotificationId $notificationId,
        CorrelationId $correlationId,
        Priority $priority,
    ): void {
        DispatchNotificationJob::dispatch(
            $notificationId->toString(),
            $correlationId->toString(),
        )->onQueue($this->queueFor($priority));
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
            Priority::High   => 'notifications-high',
            Priority::Normal => 'notifications-default',
            Priority::Low    => 'notifications-low',
        };
    }
}