<?php

declare(strict_types=1);

namespace EventPulse\Tests\Unit\Application\Notification\Channel\Doubles;

use EventPulse\Application\Notification\Channel\Exception\WebhookEndpointResolutionException;
use EventPulse\Application\Notification\Channel\WebhookEndpoint;
use EventPulse\Application\Notification\Channel\WebhookEndpointResolver;
use EventPulse\Domain\Notification\ValueObject\WebhookRecipient;

/**
 * In-memory test double for `WebhookEndpointResolver`.
 *
 * Tests register endpoints (or "disabled" markers, or "missing" markers)
 * keyed by destination id and resolution returns or throws as configured.
 * This is the test-suite analogue of the Eloquent-backed resolver Day 9
 * will introduce — the production class is replaced; the contract being
 * tested is identical.
 */
final class InMemoryWebhookEndpointResolver implements WebhookEndpointResolver
{
    /** @var array<string, WebhookEndpoint> */
    private array $endpoints = [];

    /** @var array<string, true> */
    private array $disabled = [];

    public function register(string $destinationId, WebhookEndpoint $endpoint): void
    {
        $this->endpoints[$destinationId] = $endpoint;
    }

    public function markDisabled(string $destinationId): void
    {
        $this->disabled[$destinationId] = true;
        unset($this->endpoints[$destinationId]);
    }

    #[\Override]
    public function resolve(WebhookRecipient $recipient): WebhookEndpoint
    {
        $id = $recipient->destinationId();

        if (isset($this->disabled[$id])) {
            throw WebhookEndpointResolutionException::disabled($recipient);
        }

        return $this->endpoints[$id]
            ?? throw WebhookEndpointResolutionException::notFound($recipient);
    }
}
