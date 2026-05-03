<?php

declare(strict_types=1);

namespace EventPulse\Infrastructure\Notification\Persistence;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent persistence representation of a `DeadLetterMark`.
 *
 * Not the domain entity. The domain entity is `EventPulse\Domain\
 * Notification\Entity\DeadLetterMark`. This is the row mapper.
 *
 * Used by:
 *  - `EloquentNotificationRepository` (saves and rehydrates the mark
 *    as part of the Notification aggregate),
 *  - `EloquentDeadLetteredNotificationsRepository` (the DLQ read
 *    model — list with filters and pagination, no aggregate
 *    rehydration on the list path).
 *
 * @property string                          $id
 * @property string                          $notification_id
 * @property string                          $reason
 * @property \Illuminate\Support\Carbon      $dead_lettered_at
 * @property string|null                     $replay_notification_id
 * @property \Illuminate\Support\Carbon|null $replayed_at
 * @property \Illuminate\Support\Carbon      $created_at
 * @property \Illuminate\Support\Carbon      $updated_at
 */
final class EloquentDeadLetterMark extends Model
{
    use HasUuids;

    protected $table = 'dead_letter_marks';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = [];

    protected $casts = [
        'dead_lettered_at' => 'datetime',
        'replayed_at'      => 'datetime',
    ];
}
