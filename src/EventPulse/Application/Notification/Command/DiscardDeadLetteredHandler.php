<?php

declare(strict_types=1);

namespace EventPulse\Application\Notification\Command;

use EventPulse\Application\Notification\DeadLetter\Exception\DeadLetteredNotificationNotFoundException;
use EventPulse\Application\Shared\Clock;
use EventPulse\Domain\Notification\Enum\NotificationStatus;
use EventPulse\Domain\Notification\Repository\NotificationRepository;
use EventPulse\Domain\Notification\ValueObject\NotificationId;

/**
 * Use case: discard a dead-lettered notification from the DLQ view.
 *
 * "Discard" is an acknowledgment: the operator says "I've seen this, I don't
 * need to act on it." The notification row and its attempt history are
 * preserved for audit; only the `dead_letter_marks.discarded_at` column is
 * set. The DLQ list query (`GET /dlq`) excludes discarded entries by default.
 *
 * **Why no aggregate mutation.** Discard has no domain semantics — there is
 * no invariant to enforce, no domain event to raise, no state machine to
 * advance. It is a pure operational annotation. The targeted write is
 * expressed through `NotificationRepository::markDiscarded()`, a port method
 * that belongs to the domain boundary and has a clean in-memory implementation
 * for unit tests. This keeps the Application layer free of Infrastructure
 * imports while avoiding the ceremony of a full aggregate load/mutate/save.
 *
 * **Idempotency.** Discarding an already-discarded entry is a no-op (204
 * again). `markDiscarded()` implementations are required by the interface
 * contract to be idempotent.
 *
 * **Tenant isolation.** The handler loads the notification via `findById()`
 * and checks `api_key_id` explicitly before calling `markDiscarded()`. The
 * repository write is keyed by `NotificationId` — a value the handler derives
 * from the loaded aggregate, not from user input — so the tenant check cannot
 * be bypassed.
 */
final class DiscardDeadLetteredHandler
{
    public function __construct(
        private readonly NotificationRepository $repository,
        private readonly Clock $clock,
    ) {}

    public function __invoke(DiscardDeadLetteredCommand $command): void
    {
        try {
            $id = NotificationId::fromString($command->notificationId);
        } catch (\InvalidArgumentException) {
            throw new DeadLetteredNotificationNotFoundException(
                "DLQ entry {$command->notificationId} not found.",
            );
        }

        $notification = $this->repository->findById($id);

        if ($notification === null || $notification->apiKeyId() !== $command->apiKeyId) {
            throw new DeadLetteredNotificationNotFoundException(
                "DLQ entry {$command->notificationId} not found.",
            );
        }

        if ($notification->status() !== NotificationStatus::DeadLettered) {
            throw new DeadLetteredNotificationNotFoundException(
                "DLQ entry {$command->notificationId} not found.",
            );
        }

        $this->repository->markDiscarded($id, $this->clock->now());
    }
}
