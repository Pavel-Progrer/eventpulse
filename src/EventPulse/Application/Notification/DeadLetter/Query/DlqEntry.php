<?php

declare(strict_types=1);

namespace EventPulse\Application\Notification\DeadLetter\Query;

use DateTimeImmutable;
use EventPulse\Domain\Notification\Enum\Channel;

/**
 * Read-model projection of one entry in the dead-letter queue.
 *
 * Distinct from the Notification aggregate — this is a flat,
 * pagination-shaped view that powers `GET /api/v1/dlq`. It carries
 * only the fields the list endpoint exposes (per the OpenAPI
 * `DlqEntry` schema), so the list query never has to load attempts
 * arrays or the rest of the aggregate.
 *
 * Why a separate projection rather than reusing the aggregate:
 *  - **Efficiency.** The list is typically scanned through hundreds of
 *    entries with filters; loading whole aggregates would do hundreds
 *    of N+1 queries for `attempts`. A flat projection is one query.
 *  - **Stability.** The list response shape is part of the API
 *    contract; tying it to the aggregate would mean every aggregate
 *    refactor risks an API regression.
 *  - **Read-model clarity.** This is the application layer's "what the
 *    DLQ list returns" type — distinct from the persistence layer's
 *    Eloquent rows and the HTTP layer's API resource.
 *
 * The single-inspection endpoint (`GET /api/v1/dlq/{id}`) loads the
 * full aggregate via `NotificationRepository::findById()`. That
 * endpoint exposes attempts and full payload, so it earns the cost of
 * full hydration — and the equivalent of `finalAttemptAt` is computed
 * by `Notification::finalAttemptAt()` directly off the loaded
 * collection (no extra DB round-trip).
 *
 * Not a Domain value object — DLQ entries are a read-model concept
 * coming from a *projection* over multiple aggregates and tables. They
 * have no invariants of their own; the entity's invariants live on the
 * `DeadLetterMark` and `Notification` aggregates, which produced this
 * row.
 *
 * On `finalAttemptAt`: the canonical definition lives on the
 * `Notification` aggregate (`Notification::finalAttemptAt()`); the SQL
 * sub-select in `EloquentDeadLetteredNotificationsRepository::list`
 * produces the same value over the persisted attempts table for
 * efficiency at scale. The two implementations express the same
 * "max of attempts.completed_at, or null" definition; if the
 * definition ever changes, both must move together.
 */
final readonly class DlqEntry
{
    public function __construct(
        public string $id,                          // dead_letter_marks.id
        public string $notificationId,              // notifications.id
        public string $reason,
        public Channel $channel,
        public DateTimeImmutable $deadLetteredAt,
        public ?DateTimeImmutable $finalAttemptAt,  // see class docblock — same definition as Notification::finalAttemptAt
        public ?string $replayNotificationId,
        public ?DateTimeImmutable $replayedAt,
    ) {}
}
