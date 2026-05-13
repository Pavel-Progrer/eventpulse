<?php

declare(strict_types=1);

namespace EventPulse\Application\Notification\Command;

/**
 * Command: replay a dead-lettered notification.
 *
 * A replay creates a new `Notification` aggregate with identical channel,
 * recipient, payload, and priority. The original aggregate stays in the
 * `dead_lettered` state; its `DeadLetterMark` gains the new notification's id
 * and the replay timestamp.
 *
 * Idempotency: the caller must supply an `Idempotency-Key`. If a replay with
 * the same key was previously accepted, the handler returns the already-created
 * notification rather than creating a second one (the same dedup logic as
 * `SubmitNotificationCommand`).
 */
final readonly class ReplayDeadLetteredCommand
{
    public function __construct(
        public string $notificationId,
        public string $apiKeyId,
        public string $idempotencyKey,
        public ?string $correlationId,
    ) {}
}
