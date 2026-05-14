<?php

declare(strict_types=1);

namespace EventPulse\Application\Notification\DeadLetter\Exception;

/**
 * The requested dead-letter entry does not exist or is not visible to
 * the caller's tenant.
 *
 * Same exception for "not found" and "not yours" by design: leaking
 * "this id exists but isn't yours" to a caller who guesses a
 * cross-tenant id is an information disclosure. The HTTP layer renders
 * both cases as `404 Not Found`.
 *
 * Also raised when the notification exists but is not in the
 * `dead_lettered` status — a request to inspect a `dispatched` or
 * `queued` notification through the DLQ endpoint is a category error
 * and the response is the same 404. (The status endpoint, when it
 * ships, is the right place to query non-dead-lettered notifications.)
 */
final class DeadLetteredNotificationNotFoundException extends \RuntimeException
{
    public function __construct(string $notificationId)
    {
        parent::__construct(sprintf(
            'No dead-lettered notification with id "%s" is visible in this scope.',
            $notificationId,
        ));
    }
}
