<?php

declare(strict_types=1);

namespace EventPulse\Application\WebhookDestination\Query;

use EventPulse\Domain\WebhookDestination\Repository\WebhookDestinationRepository;

/**
 * Handles `ListWebhookDestinationsQuery`.
 *
 * Asks the repository for `$limit + 1` rows to detect whether a next page
 * exists, trims the result back to `$limit`, and encodes the last id as the
 * next cursor if the page is full.
 *
 * Why `limit + 1` instead of a separate `COUNT(*)`:
 *  The same "over-fetch by one" trick used by `ListDeadLetteredQueryHandler`
 *  (ADR-0006 §3). One query instead of two; no MVCC snapshot inconsistency
 *  between the count and the data fetch; constant-time pagination regardless
 *  of total row count.
 */
final class ListWebhookDestinationsQueryHandler
{
    private const int MAX_LIMIT = 100;

    public function __construct(
        private readonly WebhookDestinationRepository $repository,
    ) {}

    public function __invoke(ListWebhookDestinationsQuery $query): ListWebhookDestinationsResult
    {
        $limit = min($query->limit, self::MAX_LIMIT);

        $rows = $this->repository->listForApiKey(
            apiKeyId: $query->apiKeyId,
            limit: $limit,
            afterId: $query->afterId,
        );

        // Over-fetch by one means count > limit implies a next page exists.
        $hasNextPage = count($rows) > $limit;

        if ($hasNextPage) {
            array_pop($rows);
        }

        $nextCursor = $hasNextPage && $rows !== []
            ? end($rows)->id()->toString()
            : null;

        return new ListWebhookDestinationsResult(
            destinations: $rows,
            nextCursor: $nextCursor,
        );
    }
}
