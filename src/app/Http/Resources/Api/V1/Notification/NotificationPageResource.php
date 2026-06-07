<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Notification;

use EventPulse\Application\Notification\Query\NotificationPage;
use EventPulse\Domain\Notification\Aggregate\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Renders a `NotificationPage` as the OpenAPI `PaginatedNotifications` schema.
 *
 * Shape:
 * ```json
 * {
 *   "data": [ { ...Notification } ],
 *   "pagination": { "next_cursor": "..." }
 * }
 * ```
 *
 * The `pagination` key matches the project-wide convention used by the DLQ
 * list (`DlqEntryPageResource`) and the OpenAPI `Pagination` schema — not
 * `meta`, which is a Laravel-ism that predates the project's explicit shape.
 *
 * `next_cursor` is null when there are no further pages. The OpenAPI
 * `Pagination` schema marks it nullable, so the field is always present.
 *
 * @property-read NotificationPage $resource
 */
final class NotificationPageResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $page = $this->resource;

        return [
            'data' => array_map(
                static fn (Notification $n): array => NotificationResource::make($n)->toArray($request),
                $page->items,
            ),
            'pagination' => [
                'next_cursor' => $page->nextCursor,
            ],
        ];
    }
}
