<?php

declare(strict_types=1);

namespace EventPulse\Application\Notification\Command;

/**
 * Thrown when a caller tries to replay a dead-lettered notification that was
 * already replayed by a *different* idempotency key.
 *
 * This results in HTTP 409. The message is intentionally terse — the 409
 * response body can carry the existing `replay_notification_id` for clients
 * that want to look it up.
 *
 * Distinct from `IdempotencyConflictException` (which is a same-key conflict
 * on the *submit* path) and from `DeadLetteredNotificationNotFoundException`
 * (which is the "not found / wrong tenant" case).
 */
final class AlreadyReplayedException extends \RuntimeException
{
    public function __construct(string $message = 'This DLQ entry has already been replayed.')
    {
        parent::__construct($message);
    }
}
