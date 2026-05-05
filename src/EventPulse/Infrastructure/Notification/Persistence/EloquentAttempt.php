<?php

declare(strict_types=1);

namespace EventPulse\Infrastructure\Notification\Persistence;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent persistence representation of an `Attempt`.
 *
 * Not the domain entity — the domain entity is `EventPulse\Domain\
 * Notification\Entity\Attempt`, which has zero framework dependencies.
 * This class is its persistence twin: a row mapper for the `attempts`
 * table.
 *
 * @property string                          $id
 * @property string                          $notification_id
 * @property int                             $number
 * @property \Illuminate\Support\Carbon      $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property bool|null                       $succeeded
 * @property string|null                     $classification
 * @property string|null                     $reason
 * @property \Illuminate\Support\Carbon      $created_at
 * @property \Illuminate\Support\Carbon      $updated_at
 */
final class EloquentAttempt extends Model
{
    use HasUuids;

    protected $table = 'attempts';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Always populated from controlled aggregate state in the repository,
     * never from user input.
     *
     * @var list<string>
     */
    protected $guarded = [];

    protected $casts = [
        'number'       => 'integer',
        'succeeded'    => 'boolean',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];
}
