<?php

declare(strict_types=1);

namespace EventPulse\Application\Notification\Query;

use EventPulse\Domain\Notification\Aggregate\Notification;

/**
 * Read-model page returned by `ListNotificationsQueryHandler`.
 *
 * Mirrors the DLQ's `DlqEntryPage` structure. The `nextCursor` field is null
 * when there are no further rows; the HTTP resource maps null to a missing
 * key rather than `"next_cursor": null` — either is valid per the OpenAPI
 * spec, but omission is less surprising for clients checking `isset()`.
 */
final readonly class NotificationPage
{
    /**
     * @param  list<Notification>  $items
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {}
}
