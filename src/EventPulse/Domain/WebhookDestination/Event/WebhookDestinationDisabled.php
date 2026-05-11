<?php

declare(strict_types=1);

namespace EventPulse\Domain\WebhookDestination\Event;

use DateTimeImmutable;
use EventPulse\Domain\DomainEvent;
use EventPulse\Domain\Notification\ValueObject\CorrelationId;
use EventPulse\Domain\WebhookDestination\ValueObject\WebhookDestinationId;

/**
 * Raised when an operator disables a webhook destination.
 *
 * After this event: the resolver returns `WebhookEndpointResolutionException::disabled()`
 * for any dispatch attempt targeting this destination, and the HTTP submission
 * endpoint rejects new notifications pointing at it with a 422.
 */
final class WebhookDestinationDisabled extends DomainEvent
{
    public function __construct(
        private readonly WebhookDestinationId $destinationId,
        private readonly string $apiKeyId,
        DateTimeImmutable $occurredAt,
        CorrelationId $correlationId,
    ) {
        parent::__construct($occurredAt, $correlationId);
    }

    public function destinationId(): WebhookDestinationId
    {
        return $this->destinationId;
    }

    public function apiKeyId(): string
    {
        return $this->apiKeyId;
    }
}
