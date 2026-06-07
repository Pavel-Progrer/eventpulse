<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Notification;

use App\Http\Resources\Api\V1\Notification\NotificationResource;
use App\Models\ApiKey;
use EventPulse\Application\Notification\Query\GetNotificationQuery;
use EventPulse\Application\Notification\Query\GetNotificationQueryHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/notifications/{id}` — inspect a single notification.
 *
 * Required scope: `notifications:read`.
 *
 * Returns the full `Notification` schema including attempt history. Payload
 * is omitted by default; pass `?include=payload` with a key that holds
 * `admin` scope to include it.
 *
 * Tenant isolation: the handler compares the notification's `api_key_id`
 * against the authenticated key; mismatches produce 404, not 403 (no
 * information disclosure — see `NotificationNotFoundException` docblock).
 */
final class GetNotificationController
{
    public function __construct(
        private readonly GetNotificationQueryHandler $handler,
    ) {}

    public function __invoke(Request $request, string $id): JsonResponse
    {
        /** @var ApiKey $apiKey */
        $apiKey = $request->attributes->get('api_key');

        $notification = ($this->handler)(new GetNotificationQuery(
            notificationId: $id,
            apiKeyId: (string) $apiKey->id,
        ));

        // `include=payload` is reserved for admin-scoped keys (Phase 1
        // defers actual admin scope; the flag is wired but the scope gate
        // ships in a later iteration). For now, always omit the payload
        // unless the caller has explicitly been granted admin scope AND
        // passes `include=payload`.
        $hasAdminScope = in_array('admin', (array) $apiKey->scopes, strict: true);
        $includePayload = $hasAdminScope
            && str_contains((string) $request->query('include', ''), 'payload');

        $resource = $includePayload
            ? NotificationResource::makeWithPayload($notification)
            : NotificationResource::make($notification);

        return $resource->response();
    }
}
