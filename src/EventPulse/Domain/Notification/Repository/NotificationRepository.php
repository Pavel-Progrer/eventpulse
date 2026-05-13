<?php

declare(strict_types=1);

namespace EventPulse\Domain\Notification\Repository;

use DateTimeImmutable;
use EventPulse\Domain\Notification\Aggregate\Notification;
use EventPulse\Domain\Notification\ValueObject\IdempotencyKey;
use EventPulse\Domain\Notification\ValueObject\NotificationId;

/**
 * Persistence contract for the Notification aggregate.
 *
 * Lives in the domain layer because the *contract* belongs to the domain
 * (the aggregate is what is being persisted; the rules of the aggregate
 * are what the contract must respect). The *implementation* lives in
 * the infrastructure layer (`EloquentNotificationRepository`).
 *
 * The repository is intentionally minimal:
 *   - `save()`                — persist a new aggregate or an updated one.
 *   - `findById()`            — reconstitute an aggregate by id, or null.
 *   - `findByIdempotencyKey()` — dedup detection for the idempotent submit and
 *                               replay paths.
 *   - `markDiscarded()`       — stamp a dead-letter mark as acknowledged by an
 *                               operator without loading or mutating the full
 *                               aggregate (see below).
 *
 * **Why `markDiscarded()` belongs here rather than on a separate port.**
 * Discard is a write operation scoped to the notification's persistence row.
 * Adding a dedicated `DeadLetterMarkRepository` for a single method that
 * touches one column would introduce a new port, a new binding, and a new test
 * double for no architectural gain — the cohesion cost outweighs the
 * separation benefit at this scale. If the dead-letter mark grows its own
 * lifecycle (e.g. discard reasons, operator notes), a dedicated repository
 * becomes the right extraction. For now, one port for one aggregate.
 *
 * **Why not go through `save()`.**
 * Discard has no domain semantics — no invariant to enforce, no event to
 * raise, no aggregate state to advance. Loading the full aggregate, mutating
 * a field, and calling `save()` would be ceremony that slows tests and hides
 * intent. `markDiscarded()` is a targeted write that says exactly what it
 * does. The DLQ list query (`GET /dlq`) already filters on `discarded_at IS
 * NULL`; this method is the write side of that contract.
 *
 * The repository raises no domain events of its own. Domain events live on
 * the aggregate and are released by the application layer via
 * `Notification::pullPendingEvents()` after persistence (see ADR-0002 §3).
 *
 * Framework note: this interface has zero Laravel dependencies. Implementations
 * are free to use Eloquent, Doctrine, or any other persistence mechanism
 * without changing this contract.
 */
interface NotificationRepository
{
    /**
     * Persist the aggregate. Idempotent at the row level: callers may invoke
     * this multiple times with the same aggregate to write its current state.
     *
     * Implementations must:
     *  1. Upsert the notification row keyed by `NotificationId`.
     *  2. Persist all attempts and the dead-letter mark consistently with the
     *     aggregate's invariants.
     *  3. Be transactional: either all changes commit or none do.
     */
    public function save(Notification $notification): void;

    /**
     * Reconstitute a Notification by its identity, or return null if no
     * record exists. Hydration goes through `Notification::reconstitute()`,
     * which raises no events.
     */
    public function findById(NotificationId $id): ?Notification;

    /**
     * Find a previously-submitted Notification by the (api_key_id, idempotency_key)
     * tuple. Returns null when no prior submission matches.
     *
     * Idempotency keys are scoped to the API key — two different callers using
     * the same key value do not collide.
     */
    public function findByIdempotencyKey(string $apiKeyId, IdempotencyKey $key): ?Notification;

    /**
     * Stamp a dead-letter mark as discarded at the given timestamp.
     *
     * Idempotent: calling this on an already-discarded notification is a no-op.
     * The notification row and its attempt history are not modified — only the
     * `dead_letter_marks.discarded_at` column is set.
     *
     * The caller is responsible for verifying that the notification exists,
     * is owned by the right API key, and is in the `dead_lettered` state before
     * invoking this method. The repository does not re-validate those conditions.
     */
    public function markDiscarded(NotificationId $id, DateTimeImmutable $at): void;
}
