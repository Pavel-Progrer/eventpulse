<?php

declare(strict_types=1);

namespace EventPulse\Application\Notification\DeadLetter\Query;

/**
 * Read-model port for the dead-letter queue.
 *
 * This is intentionally separate from the domain `NotificationRepository`:
 *  - The domain repository's job is to load and save *aggregates* — it
 *    is write-shaped, with `findById` and `save` as its core methods.
 *    Adding paginated/filtered list methods would conflate two
 *    responsibilities.
 *  - The DLQ list endpoint is a *projection* — it returns a flat,
 *    aggregate-free shape and exists at the application layer where the
 *    use case lives.
 *
 * The Application layer is the right home for this port (not the
 * Domain layer): a "list dead-lettered notifications with these
 * filters" question is an operational/observability concern, not an
 * invariant of what a Notification *is*. ADR-0002's "domain ports for
 * domain rules; application ports for application orchestration"
 * convention applies — same shape as `RetryPolicy` (ADR-0005 §1).
 *
 * Implementations live in Infrastructure
 * (`EloquentDeadLetteredNotificationsRepository`).
 */
interface DeadLetteredNotificationsRepository
{
    /**
     * List dead-lettered notifications matching the query, paginated by
     * cursor. Implementations sort by `dead_lettered_at` descending
     * (most-recent-first) so operators see new failures at the top.
     *
     * Returns an empty page (no entries, no cursor) when no rows match.
     */
    public function list(ListDeadLetteredQuery $query): DlqEntryPage;
}
