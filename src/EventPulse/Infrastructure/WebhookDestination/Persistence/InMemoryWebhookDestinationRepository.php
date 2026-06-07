<?php

declare(strict_types=1);

namespace EventPulse\Infrastructure\WebhookDestination\Persistence;

use EventPulse\Domain\WebhookDestination\Aggregate\WebhookDestination;
use EventPulse\Domain\WebhookDestination\Repository\WebhookDestinationRepository;
use EventPulse\Domain\WebhookDestination\ValueObject\WebhookDestinationId;

/**
 * In-memory `WebhookDestinationRepository` for unit and integration tests.
 *
 * Stores aggregates in a plain associative array keyed by destination id.
 * Secrets are stored alongside the aggregate in a separate map so tests
 * can assert on encryption without depending on the real Encrypter.
 *
 * Usage in tests:
 * ```php
 * $repo = new InMemoryWebhookDestinationRepository();
 * $repo->save($destination, 'my-secret-32-chars-minimum-length');
 * $found = $repo->findById($id, $apiKeyId);
 * self::assertSame('my-secret-32-chars-minimum-length', $repo->secretFor($id));
 * ```
 */
final class InMemoryWebhookDestinationRepository implements WebhookDestinationRepository
{
    /** @var array<string, WebhookDestination> */
    private array $store = [];

    /** @var array<string, string> */
    private array $secrets = [];

    #[\Override]
    public function save(WebhookDestination $destination, ?string $secret = null): void
    {
        $key = $destination->id()->toString();

        $this->store[$key] = $destination;

        if ($secret !== null) {
            $this->secrets[$key] = $secret;
        }
    }

    #[\Override]
    public function findById(WebhookDestinationId $id, string $apiKeyId): ?WebhookDestination
    {
        $destination = $this->store[$id->toString()] ?? null;

        if ($destination === null || $destination->apiKeyId() !== $apiKeyId) {
            return null;
        }

        return $destination;
    }

    #[\Override]
    public function findActiveById(WebhookDestinationId $id, string $apiKeyId): ?WebhookDestination
    {
        $destination = $this->findById($id, $apiKeyId);

        if ($destination === null || ! $destination->isActive()) {
            return null;
        }

        return $destination;
    }

    #[\Override]
    public function listForApiKey(string $apiKeyId, int $limit, ?string $afterId = null): array
    {
        $all = array_values(array_filter(
            $this->store,
            fn (WebhookDestination $d): bool => $d->apiKeyId() === $apiKeyId,
        ));

        // Newest first.
        usort($all, fn (WebhookDestination $a, WebhookDestination $b): int => $b->createdAt() <=> $a->createdAt()
        );

        if ($afterId !== null) {
            $offset = null;

            foreach ($all as $i => $d) {
                if ($d->id()->toString() === $afterId) {
                    $offset = $i + 1;
                    break;
                }
            }

            if ($offset !== null) {
                $all = array_slice($all, $offset);
            }
        }

        return array_slice($all, 0, $limit + 1);
    }

    /**
     * Returns the plaintext secret stored for a destination.
     * Available only in tests — no production code should call this.
     */
    public function secretFor(WebhookDestinationId $id): ?string
    {
        return $this->secrets[$id->toString()] ?? null;
    }

    /**
     * Returns all stored destinations, regardless of tenant.
     * Useful for assertion helpers in tests.
     *
     * @return WebhookDestination[]
     */
    public function all(): array
    {
        return array_values($this->store);
    }
}
