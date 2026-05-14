<?php

declare(strict_types=1);

namespace EventPulse\Application\WebhookDestination\Command;

/**
 * Disables an existing webhook destination.
 *
 * Renders as a `DELETE /api/v1/webhook-destinations/{id}` response (204)
 * rather than a hard delete — the destination row and its historical
 * notifications are preserved (domain.md §5.2.5).
 */
final readonly class DisableWebhookDestinationCommand
{
    public function __construct(
        public string $destinationId,
        public string $apiKeyId,
        public ?string $correlationId,
    ) {}
}
