<?php

declare(strict_types=1);

namespace EventPulse\Tests\Unit\Application\Support;

use EventPulse\Domain\Notification\Aggregate\Notification;
use EventPulse\Domain\Notification\Repository\NotificationRepository;
use EventPulse\Domain\Notification\ValueObject\IdempotencyKey;
use EventPulse\Domain\Notification\ValueObject\NotificationId;

/**
 * Hash-map-backed `NotificationRepository` for unit tests.
 *
 * Stores aggregate references directly — no serialisation, no copying. Tests
 * therefore observe the same object the handler persisted, which makes
 * assertions about post-persistence state straightforward (e.g. `pullPending
 * Events()` was called).
 *
 * Behaviour mirrors the real repository's contract:
 *  - `save()` is upsert-by-id.
 *  - `findById()` returns the same aggregate instance previously saved.
 *  - `findByIdempotencyKey()` matches on both api_key_id and the key value.
 */
final class InMemoryNotificationRepository implements NotificationRepository
{
    /** @var array<string, Notification> Indexed by NotificationId string. */
    private array $byId = [];

    #[\Override]
    public function save(Notification $notification): void
    {
        $this->byId[$notification->id()->toString()] = $notification;
    }

    #[\Override]
    public function findById(NotificationId $id): ?Notification
    {
        return $this->byId[$id->toString()] ?? null;
    }

    #[\Override]
    public function findByIdempotencyKey(string $apiKeyId, IdempotencyKey $key): ?Notification
    {
        foreach ($this->byId as $notification) {
            if ($notification->apiKeyId() === $apiKeyId
                && $notification->idempotencyKey()->equals($key)
            ) {
                return $notification;
            }
        }

        return null;
    }

    /**
     * Test helper: number of saved notifications. Useful to assert that the
     * handler wrote exactly once for a given case.
     */
    public function count(): int
    {
        return count($this->byId);
    }

    /**
     * @return list<Notification>
     */
    public function all(): array
    {
        return array_values($this->byId);
    }
}
