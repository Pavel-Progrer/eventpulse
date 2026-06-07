<?php

declare(strict_types=1);

namespace EventPulse\Domain\WebhookDestination\Aggregate;

use DateTimeImmutable;
use EventPulse\Domain\DomainEvent;
use EventPulse\Domain\Notification\ValueObject\CorrelationId;
use EventPulse\Domain\WebhookDestination\Enum\WebhookDestinationStatus;
use EventPulse\Domain\WebhookDestination\Event\WebhookDestinationDisabled;
use EventPulse\Domain\WebhookDestination\Event\WebhookDestinationRegistered;
use EventPulse\Domain\WebhookDestination\Exception\WebhookDestinationAlreadyDisabledException;
use EventPulse\Domain\WebhookDestination\ValueObject\WebhookDestinationId;

/**
 * The WebhookDestination aggregate root (domain.md §3.2).
 *
 * A webhook destination is a named, operator-registered HTTPS endpoint that
 * receives notification payloads. It is referenced by `WebhookRecipient` in
 * the Notification aggregate but has an independent lifecycle.
 *
 * Invariants enforced here (domain.md §5.2):
 *  1. Identity is immutable.
 *  2. URL scheme is `https://` (enforced at construction).
 *  3. The secret is write-once — it is stored encrypted and never returned
 *     after the creation response. The aggregate does NOT hold the plaintext
 *     secret; the application layer passes it to the repository which encrypts
 *     it before persistence. The aggregate carries only the destination's URL
 *     and metadata.
 *  4. Disabled destinations cannot be used for new notifications.
 *  5. Disabling is terminal (no re-enable in Phase 1).
 *
 * Secret handling:
 *  The signing secret is never a field on this aggregate. It is a pure
 *  infrastructure concern: the application layer receives it from the HTTP
 *  request, passes it as a parameter to the repository's `save()`, and the
 *  repository encrypts it at rest using Laravel's built-in encrypter. The
 *  `EloquentWebhookEndpointResolver` decrypts it when building a
 *  `WebhookEndpoint` for dispatch. By keeping the secret off the aggregate,
 *  we avoid it ever appearing in domain events, logs, or serialised state.
 *
 * Framework note: zero Laravel dependencies. No Illuminate imports.
 */
final class WebhookDestination
{
    /** @var DomainEvent[] */
    private array $pendingEvents = [];

    // ---------------------------------------------------------------------------
    // Construction
    // ---------------------------------------------------------------------------

    private function __construct(
        private readonly WebhookDestinationId $id,
        private readonly string $apiKeyId,
        private readonly string $url,
        private readonly ?string $name,
        private WebhookDestinationStatus $status,
        private readonly DateTimeImmutable $createdAt,
    ) {}

    /**
     * Registers a new webhook destination.
     *
     * The `$secret` parameter is passed through to the caller so the
     * application layer can hand it to the repository for encrypted
     * persistence. It is **never** stored on the aggregate.
     *
     * Enforces:
     *  - Invariant §5.2.2: URL must be HTTPS.
     *
     * Raises: WebhookDestinationRegistered
     */
    public static function register(
        WebhookDestinationId $id,
        string $apiKeyId,
        string $url,
        ?string $name,
        DateTimeImmutable $now,
        CorrelationId $correlationId,
    ): self {
        if (trim($url) !== $url || strlen($url) > 2048) {
            throw new \InvalidArgumentException(
                'Webhook destination URL must not have leading/trailing whitespace and must not exceed 2048 characters.',
            );
        }

        if (! str_starts_with($url, 'https://')) {
            throw new \InvalidArgumentException(sprintf(
                'Webhook destination URL must use https://; got "%s". '
                .'HTTP endpoints are rejected (domain.md §5.2.2): the system signs '
                .'deliveries and relies on TLS to protect the payload in transit.',
                $url,
            ));
        }

        if ($name !== null && mb_strlen($name) > 128) {
            throw new \InvalidArgumentException(
                'Webhook destination name must not exceed 128 characters.',
            );
        }

        $destination = new self(
            id: $id,
            apiKeyId: $apiKeyId,
            url: $url,
            name: $name,
            status: WebhookDestinationStatus::Active,
            createdAt: $now,
        );

        $destination->recordEvent(new WebhookDestinationRegistered(
            destinationId: $id,
            apiKeyId: $apiKeyId,
            url: $url,
            name: $name,
            occurredAt: $now,
            correlationId: $correlationId,
        ));

        return $destination;
    }

    // ---------------------------------------------------------------------------
    // Lifecycle transitions
    // ---------------------------------------------------------------------------

    /**
     * Disables this destination.
     *
     * Enforces:
     *  - Invariant §5.2.4: already-disabled destinations cannot be disabled again.
     *  - Invariant §5.2.5: historical notifications are unaffected; the caller
     *    is responsible for not dispatching new notifications against a disabled
     *    destination (the resolver throws `WebhookEndpointResolutionException::disabled()`).
     *
     * Raises: WebhookDestinationDisabled
     */
    public function disable(DateTimeImmutable $now, CorrelationId $correlationId): void
    {
        if (! $this->status->canDisable()) {
            throw new WebhookDestinationAlreadyDisabledException(
                sprintf(
                    'WebhookDestination %s is already disabled and cannot be disabled again.',
                    $this->id->toString(),
                )
            );
        }

        $this->status = WebhookDestinationStatus::Disabled;

        $this->recordEvent(new WebhookDestinationDisabled(
            destinationId: $this->id,
            apiKeyId: $this->apiKeyId,
            occurredAt: $now,
            correlationId: $correlationId,
        ));
    }

    // ---------------------------------------------------------------------------
    // Reconstitution
    // ---------------------------------------------------------------------------

    /**
     * Rebuilds from persistence without raising domain events.
     */
    public static function reconstitute(
        WebhookDestinationId $id,
        string $apiKeyId,
        string $url,
        ?string $name,
        WebhookDestinationStatus $status,
        DateTimeImmutable $createdAt,
    ): self {
        return new self(
            id: $id,
            apiKeyId: $apiKeyId,
            url: $url,
            name: $name,
            status: $status,
            createdAt: $createdAt,
        );
    }

    // ---------------------------------------------------------------------------
    // Domain event access
    // ---------------------------------------------------------------------------

    /**
     * Releases and clears all pending domain events.
     *
     * Called by the application layer after persistence, mirroring the same
     * pattern as `Notification::pullPendingEvents()`.
     *
     * @return DomainEvent[]
     */
    public function pullPendingEvents(): array
    {
        $events = $this->pendingEvents;
        $this->pendingEvents = [];

        return $events;
    }

    // ---------------------------------------------------------------------------
    // Accessors — only what callers genuinely need
    // ---------------------------------------------------------------------------

    public function id(): WebhookDestinationId
    {
        return $this->id;
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

    public function status(): WebhookDestinationStatus
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    // ---------------------------------------------------------------------------
    // Private helpers
    // ---------------------------------------------------------------------------

    private function recordEvent(DomainEvent $event): void
    {
        $this->pendingEvents[] = $event;
    }
}
