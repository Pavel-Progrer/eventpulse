<?php

declare(strict_types=1);

namespace EventPulse\Domain\WebhookDestination\Repository;

use EventPulse\Domain\WebhookDestination\Aggregate\WebhookDestination;
use EventPulse\Domain\WebhookDestination\ValueObject\WebhookDestinationId;

/**
 * Domain port for persisting and retrieving `WebhookDestination` aggregates.
 *
 * Why this lives in Domain and not Application:
 *  The repository interface belongs in the domain layer because the aggregate
 *  itself is a domain object. Application services depend on it via the
 *  domain port; the Eloquent implementation lives in Infrastructure.
 *
 * Secret handling:
 *  `save()` accepts the plaintext signing secret as a separate `$secret`
 *  parameter (not stored on the aggregate — see domain.md §5.2.3). The
 *  repository implementation encrypts it using Laravel's `Encrypter`
 *  before writing to the database. Read operations (`findById`,
 *  `findActiveById`) return the aggregate without the secret; the
 *  `EloquentWebhookEndpointResolver` retrieves and decrypts the secret
 *  separately when building a `WebhookEndpoint` for dispatch.
 *
 *  This separation means:
 *   - The aggregate is never contaminated with the plaintext secret.
 *   - The secret is only decrypted on the hot dispatch path, not on
 *     every management read.
 *   - Tests can use an `InMemoryWebhookDestinationRepository` without
 *     needing the encrypter at all.
 */
interface WebhookDestinationRepository
{
    /**
     * Persists a new or updated `WebhookDestination`.
     *
     * @param  string|null  $secret  Plaintext signing secret. Must be provided
     *                               on the first `save()` call for a new aggregate; may be `null` on
     *                               subsequent saves (e.g., after `disable()`) because the secret is
     *                               immutable after creation and the repository does not overwrite it
     *                               when null is passed. Implementations must enforce this contract.
     */
    public function save(WebhookDestination $destination, ?string $secret = null): void;

    /**
     * Finds a destination by id and api_key_id (tenant isolation).
     *
     * Returns null if not found OR if the destination belongs to a different
     * api_key_id — callers cannot distinguish the two cases.
     */
    public function findById(WebhookDestinationId $id, string $apiKeyId): ?WebhookDestination;

    /**
     * Finds an active destination by id and api_key_id.
     *
     * Returns null if not found, disabled, or owned by a different api_key_id.
     * Used by the `SubmitNotificationHandler` to validate webhook recipients
     * at submission time.
     */
    public function findActiveById(WebhookDestinationId $id, string $apiKeyId): ?WebhookDestination;

    /**
     * Returns all destinations for an API key, newest first, with cursor pagination.
     *
     * @return WebhookDestination[]
     */
    public function listForApiKey(string $apiKeyId, int $limit, ?string $afterId = null): array;
}
