<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Notification;

use DateTimeInterface;
use EventPulse\Domain\Notification\Aggregate\Notification;
use EventPulse\Domain\Notification\Entity\Attempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Renders a `Notification` aggregate as the OpenAPI `Notification` schema.
 *
 * Used by:
 *  - `GET /api/v1/notifications/{id}` — full aggregate with attempts.
 *  - `POST /api/v1/dlq/{id}/replay`   — the new notification (202 body).
 *
 * **`dispatched_at` derivation.** The `Notification` aggregate does not expose
 * a `dispatchedAt()` accessor — the timestamp is not stored as a first-class
 * field on the aggregate; it lives on the successful attempt. We derive it
 * here by scanning the attempts for the first one where `succeeded() === true`
 * and returning its `completedAt()`. This is read-model logic that belongs in
 * the resource, not in the domain. If it becomes a hot path, a projection is
 * the right optimisation.
 *
 * **Payload omission.** The spec states notification payloads are not returned
 * by default. `makeWithPayload()` is a named constructor for the `admin` scope
 * gate rather than a boolean parameter, which would be opaque at the call site.
 *
 * @property-read Notification $resource
 */
final class NotificationResource extends JsonResource
{
    public static $wrap = null;

    private bool $includePayload = false;

    public static function makeWithPayload(Notification $notification): self
    {
        $resource = self::make($notification);
        $resource->includePayload = true;

        return $resource;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $n = $this->resource;

        $data = [
            'id' => $n->id()->toString(),
            'channel' => $n->channel()->value,
            'recipient' => $n->recipient()->toString(),
            'priority' => $n->priority()->value,
            'status' => $n->status()->value,
            'idempotency_key' => $n->idempotencyKey()->toString(),
            'correlation_id' => $n->correlationId()->toString(),
            'replay_of_id' => $n->replayOf()?->toString(),
            'created_at' => $n->createdAt()->format(DateTimeInterface::ATOM),
            'dispatched_at' => $this->deriveDispatchedAt($n),
            'attempts' => $this->renderAttempts($n),
        ];

        if ($this->includePayload) {
            $data['payload'] = $n->payload()->toArray();
        }

        return $data;
    }

    /**
     * Derive the dispatch timestamp from the attempts collection.
     *
     * The aggregate does not carry `dispatchedAt` as a first-class field.
     * The timestamp is the `completedAt` of the first successful attempt.
     * Returns null when no successful attempt exists yet.
     */
    private function deriveDispatchedAt(Notification $n): ?string
    {
        foreach ($n->attempts() as $attempt) {
            if ($attempt->succeeded() === true) {
                return $attempt->completedAt()?->format(DateTimeInterface::ATOM);
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function renderAttempts(Notification $n): array
    {
        return array_map(
            static fn (Attempt $a): array => [
                'number' => $a->number()->toInt(),
                'started_at' => $a->startedAt()->format(DateTimeInterface::ATOM),
                'completed_at' => $a->completedAt()?->format(DateTimeInterface::ATOM),
                'succeeded' => $a->succeeded(),
                'classification' => $a->failureClassification()?->value,
                'reason' => $a->failureReason(),
            ],
            $n->attempts(),
        );
    }
}
