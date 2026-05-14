<?php

declare(strict_types=1);

namespace EventPulse\Application\Notification\DeadLetter\Query;

/**
 * Input for `GetDeadLetteredQueryHandler`.
 *
 * Carries the tuple `(notificationId, apiKeyId)` rather than the
 * notification id alone — the apiKeyId is the security context of the
 * request and is always part of the lookup. A notification belonging to
 * tenant A is never returned to tenant B's DLQ-read query, even if B
 * happens to know A's notification id.
 *
 * The handler returns the full `Notification` aggregate (loaded with
 * attempts and the dead-letter mark, thanks to the Day 8 repository
 * extension). The HTTP resource layer formats it for the wire.
 */
final readonly class GetDeadLetteredQuery
{
    public function __construct(
        public string $notificationId,
        public string $apiKeyId,
    ) {}
}
