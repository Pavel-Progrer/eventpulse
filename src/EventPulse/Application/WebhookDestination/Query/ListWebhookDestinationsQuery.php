<?php

declare(strict_types=1);

namespace EventPulse\Application\WebhookDestination\Query;

/**
 * Returns a paginated list of webhook destinations for an API key.
 *
 * `$afterId` is the cursor — the id of the last destination on the previous
 * page. Null means "start from the beginning". The repository returns at most
 * `$limit + 1` rows; a result of exactly `$limit + 1` means there is a next
 * page, and the handler trims the list back to `$limit` and sets `nextCursor`.
 */
final readonly class ListWebhookDestinationsQuery
{
    public function __construct(
        public string $apiKeyId,
        public int $limit = 20,
        public ?string $afterId = null,
    ) {}
}
