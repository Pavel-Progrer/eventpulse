<?php

declare(strict_types=1);

namespace EventPulse\Domain\Notification\Entity;

use DateTimeImmutable;
use EventPulse\Domain\Notification\ValueObject\NotificationId;

/**
 * Records the fact that a notification was dead-lettered and, optionally,
 * the id and moment of its replay (domain.md §3.1, invariant 5.1.7).
 *
 * This is an optional component of the Notification aggregate — not a separate
 * aggregate. The domain model chose this boundary because dead-lettering is a
 * state of a notification, not an independent object with its own lifecycle
 * (domain.md §3.1). A dead-letter queue endpoint returns notifications in the
 * dead-lettered state, not a separate entity type.
 *
 * The mark is created once and is effectively immutable: the only mutation
 * allowed is recording the replay (id + timestamp) when an operator replays.
 *
 * Day 8 update: `recordReplay` now takes the moment of the replay alongside
 * the new notification's id. This closes a gap with the persistence layer —
 * the `dead_letter_marks` table's CHECK constraint enforces that
 * `replay_notification_id` and `replayed_at` are both populated or both
 * null. Before today, the entity tracked only the id, leaving the timestamp
 * to be inferred at the persistence boundary; that was a small but real
 * model-truth gap. Carrying both on the entity keeps the entity the single
 * source of truth and makes the future replay handler trivial (it has $now
 * in scope already).
 */
final class DeadLetterMark
{
    private ?NotificationId $replayNotificationId = null;
    private ?DateTimeImmutable $replayedAt = null;

    /**
     * @internal Called only by the Notification aggregate root via deadLetter().
     */
    public function __construct(
        private readonly string $reason,
        private readonly DateTimeImmutable $deadLetteredAt,
    ) {}

    /**
     * Records that the dead-lettered notification was replayed, producing
     * a new notification with the given id at the given moment
     * (invariant 5.1.7).
     *
     * Called once; subsequent calls are a logic error.
     */
    public function recordReplay(
        NotificationId $replayNotificationId,
        DateTimeImmutable $replayedAt,
    ): void {
        if ($this->replayNotificationId !== null) {
            throw new \LogicException(
                'This dead-letter mark already has a replay notification recorded.'
            );
        }

        $this->replayNotificationId = $replayNotificationId;
        $this->replayedAt           = $replayedAt;
    }

    /**
     * @internal Called only by the repository during reconstitution.
     *           Hydrates the replay metadata without the "already
     *           replayed" guard, because rehydration is replaying
     *           historical state, not recording a new event.
     */
    public function reconstituteReplay(
        NotificationId $replayNotificationId,
        DateTimeImmutable $replayedAt,
    ): void {
        $this->replayNotificationId = $replayNotificationId;
        $this->replayedAt           = $replayedAt;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function deadLetteredAt(): DateTimeImmutable
    {
        return $this->deadLetteredAt;
    }

    public function replayNotificationId(): ?NotificationId
    {
        return $this->replayNotificationId;
    }

    public function replayedAt(): ?DateTimeImmutable
    {
        return $this->replayedAt;
    }

    public function wasReplayed(): bool
    {
        return $this->replayNotificationId !== null;
    }
}
