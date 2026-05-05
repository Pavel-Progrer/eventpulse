<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Dlq;

use EventPulse\Application\Notification\DeadLetter\Query\DlqEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Renders a `DlqEntry` projection as the API's `DlqEntry` JSON shape.
 *
 * Sets `wrap = null` so the resource does not auto-wrap in a `data` key
 * — the list response wraps the collection at the page level
 * (`DlqEntryPageResource`), which is where `data` actually belongs.
 *
 * @property-read DlqEntry $resource
 */
final class DlqEntryResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $entry = $this->resource;

        return [
            'id'                     => $entry->id,
            'notification_id'        => $entry->notificationId,
            'reason'                 => $entry->reason,
            'channel'                => $entry->channel->value,
            'final_attempt_at'       => $entry->finalAttemptAt?->format(\DateTimeInterface::ATOM),
            'replayed_at'            => $entry->replayedAt?->format(\DateTimeInterface::ATOM),
            'replay_notification_id' => $entry->replayNotificationId,
            'created_at'             => $entry->deadLetteredAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
