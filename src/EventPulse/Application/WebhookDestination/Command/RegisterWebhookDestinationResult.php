<?php

declare(strict_types=1);

namespace EventPulse\Application\WebhookDestination\Command;

use DateTimeImmutable;
use EventPulse\Domain\WebhookDestination\Enum\WebhookDestinationStatus;
use EventPulse\Domain\WebhookDestination\ValueObject\WebhookDestinationId;

/**
 * Carries the result of a successful `RegisterWebhookDestinationCommand`.
 *
 * The `$secret` field here is the only time the plaintext secret is returned
 * to a caller — the OpenAPI spec declares it is returned only in the creation
 * response and never again. The HTTP resource MUST include it exactly once.
 */
final readonly class RegisterWebhookDestinationResult
{
    public function __construct(
        public WebhookDestinationId $id,
        public string $url,
        public ?string $name,
        public string $secret,
        public WebhookDestinationStatus $status,
        public DateTimeImmutable $createdAt,
    ) {}
}
