<?php

declare(strict_types=1);

namespace EventPulse\Application\Notification\Command;

/**
 * Command: discard a dead-lettered notification.
 *
 * "Discard" acknowledges the entry and removes it from the DLQ view without
 * deleting the underlying history. The implementation marks the
 * `dead_letter_marks` row with a `discarded_at` timestamp; the notification
 * row itself (and its attempt history) are preserved for audit.
 *
 * The `GET /api/v1/dlq` list excludes discarded entries by default. A future
 * `?include_discarded=true` param would let operators audit them — that is a
 * Phase 2 enhancement and explicitly deferred.
 *
 * Scope: `dlq:replay` (same scope as replay — both are operator actions that
 * change the DLQ surface area; the spec groups them under one permission gate).
 */
final readonly class DiscardDeadLetteredCommand
{
    public function __construct(
        public string $notificationId,
        public string $apiKeyId,
    ) {}
}
