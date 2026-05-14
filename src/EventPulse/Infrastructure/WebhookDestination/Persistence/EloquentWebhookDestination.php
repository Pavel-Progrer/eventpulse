<?php

declare(strict_types=1);

namespace EventPulse\Infrastructure\WebhookDestination\Persistence;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent persistence representation of a WebhookDestination.
 *
 * This is *not* the domain aggregate. The domain aggregate is
 * `EventPulse\Domain\WebhookDestination\Aggregate\WebhookDestination`, which
 * has zero framework dependencies. This class is its persistence twin: a plain
 * Eloquent model whose only job is to map the aggregate's serialised state to
 * and from the `webhook_destinations` table.
 *
 * Why `secret_encrypted` is a plain column and not cast to encrypted here:
 *  Laravel's `encrypted` cast uses `Crypt::encryptString()` automatically.
 *  We handle encryption/decryption explicitly in the repository to keep the
 *  infrastructure transparent and avoid any magic that could obscure how the
 *  secret is handled (e.g., accidentally exposing it via `$model->toArray()`
 *  before the cast runs). Explicit > implicit for security-sensitive columns.
 *
 * @property string                          $id
 * @property string                          $api_key_id
 * @property string                          $url
 * @property string|null                     $name
 * @property string                          $secret_encrypted
 * @property string                          $status
 * @property \Illuminate\Support\Carbon      $created_at
 * @property \Illuminate\Support\Carbon      $updated_at
 */
final class EloquentWebhookDestination extends Model
{
    use HasUuids;

    protected $table = 'webhook_destinations';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Allow mass assignment — we always populate this model from controlled
     * aggregate state in the repository, never from user input.
     *
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * Explicitly hide the encrypted secret from array/JSON serialisation.
     * This prevents the ciphertext from leaking into API responses if a
     * future refactor accidentally calls `$model->toArray()` in a resource.
     *
     * @var list<string>
     */
    protected $hidden = ['secret_encrypted'];
}
