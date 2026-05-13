<?php

declare(strict_types=1);

namespace EventPulse\Application\Notification\Query;

/**
 * Thrown when a notification id resolves to no record, or to a record whose
 * `api_key_id` does not match the authenticated caller.
 *
 * Using a single exception for both cases is deliberate: the HTTP layer maps
 * both to 404, and a distinct "wrong tenant" exception type would allow a
 * caller to enumerate other tenants' notification ids by probing for 403 vs
 * 404. Uniform 404 is the information-disclosure-safe choice. The same
 * reasoning governs `DeadLetteredNotificationNotFoundException` in the DLQ
 * inspection path.
 */
final class NotificationNotFoundException extends \RuntimeException
{
    public function __construct(string $message = 'Notification not found.')
    {
        parent::__construct($message);
    }
}
