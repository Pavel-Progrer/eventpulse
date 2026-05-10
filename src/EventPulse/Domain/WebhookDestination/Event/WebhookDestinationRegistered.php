<?php

declare(strict_types=1);

namespace EventPulse\Domain\WebhookDestination\Event;

use DateTimeImmutable;
use EventPulse\Domain\DomainEvent;
use EventPulse\Domain\Notification\ValueObject\CorrelationId;
use EventPulse\Domain\WebhookDestination\ValueObject\WebhookDestinationId;

/**
 * Raised when a new webhook destination is registered by an operator.
 *
 * The `url` is included in the event for log observability. The signing
 * secret is intentionally absent — it must never appear in domain events,
 * structured logs, or any serialised representation.
 */
final class WebhookDestinationRegistered extends DomainEvent
{
    public function __construct(
        private readonly WebhookDestinationId $destinationId,
        private readonly string $apiKeyId,
        private readonly string $url,
        private readonly ?string $name,
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

    public function url(): string
    {
        return $this->url;
    }

    public function name(): ?string
    {
        return $this->name;
    }
}
