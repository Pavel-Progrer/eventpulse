<?php

declare(strict_types=1);

namespace EventPulse\Domain\Notification\Entity;

use DateTimeImmutable;
use EventPulse\Domain\Notification\ValueObject\NotificationId;

/**
 * Records the fact that a notification was dead-lettered and, optionally,
 * the id of its replay (domain.md §3.1, invariant 5.1.7).
 *
 * This is an optional component of the Notification aggregate — not a separate
 * aggregate. The domain model chose this boundary because dead-lettering is a
 * state of a notification, not an independent object with its own lifecycle
 * (domain.md §3.1). A dead-letter queue endpoint returns notifications in the
 * dead-lettered state, not a separate entity type.
 *
 * The mark is created once and is effectively immutable: the only mutation
 * allowed is recording the replay notification id when an operator replays.
 */
final class DeadLetterMark
{
    private ?NotificationId $replayNotificationId = null;

    /**
     * @internal Called only by the Notification aggregate root via deadLetter().
     */
    public function __construct(
        private readonly string $reason,
        private readonly DateTimeImmutable $deadLetteredAt,
    ) {}

    /**
     * Records that the dead-lettered notification was replayed, producing
     * a new notification with the given id (invariant 5.1.7).
     *
     * Called once; subsequent calls are a logic error.
     */
    public function recordReplay(NotificationId $replayNotificationId): void
    {
        if ($this->replayNotificationId !== null) {
            throw new \LogicException(
                'This dead-letter mark already has a replay notification recorded.'
            );
        }

        $this->replayNotificationId = $replayNotificationId;
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

    public function wasReplayed(): bool
    {
        return $this->replayNotificationId !== null;
    }
}