<?php

declare(strict_types=1);

namespace EventPulse\Infrastructure\Notification\Persistence;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent persistence representation of a Notification.
 *
 * This is *not* the domain aggregate. The domain aggregate is
 * `EventPulse\Domain\Notification\Aggregate\Notification`, which has zero
 * framework dependencies. This class is its persistence twin: a plain Eloquent
 * model whose only job is to map the aggregate's serialised state to and from
 * the `notifications` table.
 *
 * Why two classes for one concept (per ADR-0002 §4):
 *  - The aggregate enforces invariants and raises events. Eloquent's "magic"
 *    attribute access and active-record save semantics are antithetical to
 *    that: they assume the database is the source of truth, whereas the
 *    aggregate assumes the in-memory object is.
 *  - Repository code reads from this Eloquent model and reconstitutes the
 *    aggregate via `Notification::reconstitute()`. The reverse direction —
 *    persisting an aggregate — copies aggregate state into a fresh or hydrated
 *    Eloquent row and saves.
 *
 * The class is deliberately spartan: no relations, no scopes, no observers.
 * Behaviour belongs on the aggregate; this is a row-mapper.
 *
 * @property string                          $id
 * @property string                          $api_key_id
 * @property string                          $channel
 * @property string                          $recipient
 * @property string                          $priority
 * @property array<string, mixed>            $payload
 * @property string                          $status
 * @property \Illuminate\Support\Carbon|null $dispatched_at
 * @property \Illuminate\Support\Carbon|null $scheduled_for
 * @property string                          $correlation_id
 * @property string                          $idempotency_key
 * @property string|null                     $replay_of_id
 * @property \Illuminate\Support\Carbon      $created_at
 * @property \Illuminate\Support\Carbon      $updated_at
 */
final class EloquentNotification extends Model
{
    use HasUuids;

    protected $table = 'notifications';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Allow mass assignment because we always populate this model from
     * controlled aggregate state in the repository — never from user input.
     *
     * @var list<string>
     */
    protected $guarded = [];

    protected $casts = [
        'payload'       => 'array',
        'dispatched_at' => 'datetime',
        'scheduled_for' => 'datetime',
    ];
}
