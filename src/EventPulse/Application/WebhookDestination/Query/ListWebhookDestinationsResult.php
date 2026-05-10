<?php

declare(strict_types=1);

namespace EventPulse\Application\WebhookDestination\Query;

use EventPulse\Domain\WebhookDestination\Aggregate\WebhookDestination;

/**
 * Result of `ListWebhookDestinationsQuery`.
 *
 * Mirrors the cursor-pagination shape used by the DLQ list endpoint
 * (ADR-0006): a slice of results and an opaque next-cursor string.
 * `null` next-cursor means the last page.
 */
final readonly class ListWebhookDestinationsResult
{
    /**
     * @param WebhookDestination[] $destinations
     */
    public function __construct(
        public array $destinations,
        public ?string $nextCursor,
    ) {}
}
