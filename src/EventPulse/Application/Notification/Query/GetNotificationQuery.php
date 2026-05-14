<?php

declare(strict_types=1);

namespace EventPulse\Application\Notification\Query;

/**
 * Query: fetch a single notification by its id, scoped to the calling API key.
 *
 * The `apiKeyId` constraint enforces tenant isolation — a caller can only
 * inspect notifications they originally submitted. The repository implementation
 * checks both fields; returning null for a different tenant's notification is
 * indistinguishable from "not found" at the HTTP layer (see ADR-0003 §2).
 */
final readonly class GetNotificationQuery
{
    public function __construct(
        public string $notificationId,
        public string $apiKeyId,
    ) {}
}
