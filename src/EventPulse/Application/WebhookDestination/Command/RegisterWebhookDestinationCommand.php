<?php

declare(strict_types=1);

namespace EventPulse\Application\WebhookDestination\Command;

/**
 * Registers a new webhook destination for an API key.
 *
 * `$secret` is the plaintext shared secret supplied by the operator.
 * It flows from the HTTP request → handler → repository, where it is
 * encrypted. It is never stored on the domain aggregate or in any event.
 */
final readonly class RegisterWebhookDestinationCommand
{
    public function __construct(
        public string $apiKeyId,
        public string $url,
        public string $secret,
        public ?string $name,
        public ?string $correlationId,
    ) {}
}
