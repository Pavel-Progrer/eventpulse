<?php

declare(strict_types=1);

namespace EventPulse\Infrastructure\Notification\Persistence;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

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
 *    rehydration on the list path),
 *  - `DiscardDeadLetteredHandler` (stamps `discarded_at` directly;
 *    no aggregate mutation required).
 *
 * Day 11 addition: `discarded_at` column + cast.
 * When an operator issues `DELETE /api/v1/dlq/{id}`, the handler
 * stamps this column with the current UTC timestamp. The DLQ list query
 * filters to `WHERE discarded_at IS NULL` so acknowledged entries
 * disappear from the default view without deleting any history.
 *
 * @property string $id
 * @property string $notification_id
 * @property string $reason
 * @property Carbon $dead_lettered_at
 * @property string|null $replay_notification_id
 * @property Carbon|null $replayed_at
 * @property Carbon|null $discarded_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
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
        'replayed_at' => 'datetime',
        'discarded_at' => 'datetime',
    ];
}
