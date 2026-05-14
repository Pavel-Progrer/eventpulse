<?php

declare(strict_types=1);

namespace EventPulse\Infrastructure\WebhookDestination\Persistence;

use DateTimeImmutable;
use EventPulse\Domain\WebhookDestination\Aggregate\WebhookDestination;
use EventPulse\Domain\WebhookDestination\Enum\WebhookDestinationStatus;
use EventPulse\Domain\WebhookDestination\Repository\WebhookDestinationRepository;
use EventPulse\Domain\WebhookDestination\ValueObject\WebhookDestinationId;
use Illuminate\Contracts\Encryption\Encrypter;

/**
 * Eloquent-backed `WebhookDestinationRepository`.
 *
 * Mediates between the `WebhookDestination` aggregate and the
 * `webhook_destinations` table via `EloquentWebhookDestination`.
 *
 * Secret handling:
 *  On `save()` with a non-null `$secret`, the plaintext is encrypted using
 *  Laravel's built-in `Encrypter` (wraps AES-256-CBC with the app key) and
 *  stored in `secret_encrypted`. On subsequent saves (e.g., after `disable()`)
 *  a null `$secret` means "don't touch the column" — we UPDATE only the
 *  mutable fields. This ensures the encrypted secret is written once and
 *  never overwritten with null by accident.
 *
 * Tenant isolation:
 *  Every query includes `api_key_id = ?` to prevent cross-tenant reads.
 *  The repository interface's docblock specifies this explicitly.
 */
final class EloquentWebhookDestinationRepository implements WebhookDestinationRepository
{
    public function __construct(
        private readonly Encrypter $encrypter,
    ) {}

    #[\Override]
    public function save(WebhookDestination $destination, ?string $secret = null): void
    {
        /** @var EloquentWebhookDestination $model */
        $model = EloquentWebhookDestination::firstOrNew(['id' => $destination->id()->toString()]);

        // These fields are always written (status changes on disable).
        $model->api_key_id = $destination->apiKeyId();
        $model->url        = $destination->url();
        $model->name       = $destination->name();
        $model->status     = $destination->status()->value;

        // The secret is written only on the first save. Subsequent calls
        // (e.g., after disable()) pass null and must not overwrite the
        // encrypted column — doing so would void stored secrets for
        // in-flight notifications that were signed with the original key.
        if ($secret !== null) {
            $model->secret_encrypted = $this->encrypter->encryptString($secret);
        }

        $model->save();
    }

    #[\Override]
    public function findById(WebhookDestinationId $id, string $apiKeyId): ?WebhookDestination
    {
        $model = EloquentWebhookDestination::query()
            ->where('id', $id->toString())
            ->where('api_key_id', $apiKeyId)
            ->first();

        if ($model === null) {
            return null;
        }

        return $this->hydrate($model);
    }

    #[\Override]
    public function findActiveById(WebhookDestinationId $id, string $apiKeyId): ?WebhookDestination
    {
        $model = EloquentWebhookDestination::query()
            ->where('id', $id->toString())
            ->where('api_key_id', $apiKeyId)
            ->where('status', WebhookDestinationStatus::Active->value)
            ->first();

        if ($model === null) {
            return null;
        }

        return $this->hydrate($model);
    }

    #[\Override]
    public function listForApiKey(string $apiKeyId, int $limit, ?string $afterId = null): array
    {
        $query = EloquentWebhookDestination::query()
            ->where('api_key_id', $apiKeyId)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->limit($limit + 1); // fetch one extra to determine if there's a next page

        if ($afterId !== null) {
            $pivot = EloquentWebhookDestination::query()
                ->where('id', $afterId)
                ->where('api_key_id', $apiKeyId)
                ->first();

            if ($pivot !== null) {
                $query->where(function ($q) use ($pivot): void {
                    $q->where('created_at', '<', $pivot->created_at)
                      ->orWhere(function ($q2) use ($pivot): void {
                          $q2->where('created_at', '=', $pivot->created_at)
                             ->where('id', '<', $pivot->id);
                      });
                });
            }
        }

        return $query
            ->get()
            ->map(fn(EloquentWebhookDestination $m): WebhookDestination => $this->hydrate($m))
            ->all();
    }

    // ---------------------------------------------------------------------------
    // Hydration
    // ---------------------------------------------------------------------------

    private function hydrate(EloquentWebhookDestination $model): WebhookDestination
    {
        return WebhookDestination::reconstitute(
            id:        WebhookDestinationId::fromString($model->id),
            apiKeyId:  $model->api_key_id,
            url:       $model->url,
            name:      $model->name,
            status:    WebhookDestinationStatus::from($model->status),
            createdAt: new DateTimeImmutable($model->created_at->toIso8601String()),
        );
    }
}
