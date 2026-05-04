<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Dlq;

use EventPulse\Domain\Notification\Aggregate\Notification;
use EventPulse\Domain\Notification\Entity\Attempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Renders a dead-lettered `Notification` aggregate as the OpenAPI
 * `DlqEntryDetailed` shape — the full notification (channel,
 * recipient, payload, attempts) plus DLQ metadata (the same fields
 * `DlqEntryResource` carries for the list).
 *
 * The endpoint that emits this resource (`GET /api/v1/dlq/{id}`) is
 * the only one in Phase 1 that returns the full notification shape
 * — so this resource also implements the OpenAPI `Notification`
 * schema. When the standalone `GET /api/v1/notifications/{id}`
 * endpoint ships (later milestone), it will share the
 * `notificationToArray()` projection method below as the single
 * source of truth for that shape.
 *
 * @property-read Notification $resource
 */
final class DlqEntryDetailedResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $notification = $this->resource;
        $mark         = $notification->deadLetterMark();

        // Defensive: this resource is only ever invoked with a
        // dead-lettered notification (the handler enforces it). If we
        // ever get here without a mark, something upstream broke and
        // we'd rather throw than silently render `null` for required
        // fields.
        if ($mark === null) {
            throw new \LogicException(sprintf(
                'DlqEntryDetailedResource invoked for notification %s without a DeadLetterMark.',
                $notification->id()->toString(),
            ));
        }

        return [
            // ── DlqEntry shape ──────────────────────────────────────────
            //
            // `id` and `notification_id` carry the same value today —
            // the read model's "DLQ entry id" is the `dead_letter_marks`
            // row id, but the inspect-by-notification-id path doesn't
            // need it on the wire. We expose both to match the OpenAPI
            // contract; clients that key on either get a correct value.
            'id'                     => $notification->id()->toString(),
            'notification_id'        => $notification->id()->toString(),
            'reason'                 => $mark->reason(),
            'channel'                => $notification->channel()->value,

            // `final_attempt_at` is the aggregate's own answer to "when
            // did the last completed attempt finish." The list endpoint
            // computes the equivalent value in SQL via a sub-select; the
            // two implementations express the same definition in
            // different layers (see Notification::finalAttemptAt for the
            // contract).
            'final_attempt_at'       => $notification->finalAttemptAt()?->format(\DateTimeInterface::ATOM),

            'replayed_at'            => $mark->replayedAt()?->format(\DateTimeInterface::ATOM),
            'replay_notification_id' => $mark->replayNotificationId()?->toString(),
            'created_at'             => $mark->deadLetteredAt()->format(\DateTimeInterface::ATOM),

            // ── Embedded notification ───────────────────────────────────
            'notification' => $this->notificationToArray($notification),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function notificationToArray(Notification $notification): array
    {
        return [
            'id'              => $notification->id()->toString(),
            'channel'         => $notification->channel()->value,
            'recipient'       => $notification->recipient()->toString(),
            'priority'        => $notification->priority()->value,
            'status'          => $notification->status()->value,
            'payload'         => $notification->payload()->toArray(),
            'idempotency_key' => $notification->idempotencyKey()->toString(),
            'correlation_id'  => $notification->correlationId()->toString(),
            'replay_of_id'    => $notification->replayOf()?->toString(),
            'created_at'      => $notification->createdAt()->format(\DateTimeInterface::ATOM),
            'attempts'        => $this->attemptsToArray($notification),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function attemptsToArray(Notification $notification): array
    {
        $rendered = [];

        // `Notification::attempts()` returns the array indexed by 1-based
        // attempt number; the JSON shape is a list, so we re-index here.
        foreach ($notification->attempts() as $attempt) {
            $rendered[] = $this->attemptToArray($attempt);
        }

        return $rendered;
    }

    /**
     * @return array<string, mixed>
     */
    private function attemptToArray(Attempt $attempt): array
    {
        return [
            'number'         => $attempt->number()->toInt(),
            'started_at'     => $attempt->startedAt()->format(\DateTimeInterface::ATOM),
            'completed_at'   => $attempt->completedAt()?->format(\DateTimeInterface::ATOM),
            'succeeded'      => $attempt->succeeded(),
            'classification' => $attempt->failureClassification()?->value,
            'reason'         => $attempt->failureReason(),
        ];
    }
}
