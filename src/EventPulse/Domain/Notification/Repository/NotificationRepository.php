<?php

declare(strict_types=1);

namespace EventPulse\Domain\Notification\Repository;

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
 *   - `save()`        — persist a new aggregate or an updated one. Implementations
 *                       are upserts; the aggregate itself owns its identity.
 *   - `findById()`    — reconstitute an aggregate by id, or return null if absent.
 *   - `findByIdempotencyKey()` — used by the application layer to detect repeat
 *                                submissions (per ADR-0003 and ADR-0004 once
 *                                idempotency dedup is implemented in Day 4).
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
     *     aggregate's invariants. (For Day 3 only the root row is exercised;
     *     attempts persistence is wired up in Day 4.)
     *  3. Be transactional: either all changes commit or none do.
     */
    // TODO(Day 4) Add transaction boundary
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
     * the same key value do not collide. The application layer uses this to
     * implement the idempotent-replay contract (HTTP 200 vs 202; see OpenAPI).
     */
    public function findByIdempotencyKey(string $apiKeyId, IdempotencyKey $key): ?Notification;
}
