<?php

declare(strict_types=1);

namespace EventPulse\Application\Notification\Query;

use DateTimeImmutable;
use EventPulse\Domain\Notification\Enum\Channel;
use EventPulse\Domain\Notification\Enum\NotificationStatus;

/**
 * Query: paginated list of notifications visible to one API key.
 *
 * All filter fields are optional and combine with AND semantics.
 * `statuses` and `channels` are multi-value — the caller may filter to
 * several at once (e.g. `status=queued&status=processing`).
 *
 * Pagination is cursor-based: `cursor` is an opaque string produced by the
 * previous response. The implementation encodes the `(created_at, id)` tuple
 * so the result set is stable even as rows change status between pages.
 */
final readonly class ListNotificationsQuery
{
    /**
     * @param  list<NotificationStatus>  $statuses
     * @param  list<Channel>  $channels
     */
    public function __construct(
        public string $apiKeyId,
        public array $statuses = [],
        public array $channels = [],
        public ?string $correlationId = null,
        public ?DateTimeImmutable $createdAfter = null,
        public ?DateTimeImmutable $createdBefore = null,
        public int $limit = 50,
        public ?string $cursor = null,
    ) {}
}
