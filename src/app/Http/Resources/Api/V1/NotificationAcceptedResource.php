<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use EventPulse\Application\Notification\Command\SubmitNotificationResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Response shape for `POST /api/v1/notifications`.
 *
 * Mirrors the OpenAPI `NotificationResource` schema: a thin acceptance
 * receipt — id, status, correlation_id, created_at, and a self link.
 *
 * Why not return the full `Notification` shape (with attempts, dispatched_at,
 * etc.): the create response is a *receipt* — confirmation that the system
 * accepted the request — not a status snapshot. Status is fetched via
 * `GET /api/v1/notifications/{id}` and uses a different resource. Mixing
 * the two encourages clients to treat the create response as authoritative
 * about delivery state, which it is not (delivery is asynchronous).
 *
 * The wrapped object is a `SubmitNotificationResult` (an application-layer
 * DTO), not the domain aggregate or an Eloquent row. Coupling the resource
 * to the result DTO means the HTTP shape evolves independently of how the
 * data is persisted or how the domain models the lifecycle.
 *
 * @mixin SubmitNotificationResult
 */
final class NotificationAcceptedResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var SubmitNotificationResult $result */
        $result = $this->resource;

        return [
            'id'             => $result->id->toString(),
            'status'         => $result->status->value,
            'correlation_id' => $result->correlationId->toString(),
            'created_at'     => $result->createdAt->format(DATE_ATOM),
            '_links'         => [
                'self' => sprintf('/api/v1/notifications/%s', $result->id->toString()),
            ],
        ];
    }

    /**
     * Disable the default `data` envelope. The OpenAPI schema describes the
     * resource fields at the top level, not inside a wrapper. Other resources
     * in this project follow the same convention.
     *
     * @var string|null
     */
    public static $wrap = null;
}
