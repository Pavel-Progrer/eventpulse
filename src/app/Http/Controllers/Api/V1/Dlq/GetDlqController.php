<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Dlq;

use App\Http\Resources\Api\V1\Dlq\DlqEntryDetailedResource;
use App\Models\ApiKey;
use EventPulse\Application\Notification\DeadLetter\Query\GetDeadLetteredQuery;
use EventPulse\Application\Notification\DeadLetter\Query\GetDeadLetteredQueryHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/dlq/{id}` — full inspection of one dead-lettered
 * notification.
 *
 * Returns the OpenAPI `DlqEntryDetailed` shape: the notification's full
 * state (channel, recipient, payload, attempts) plus the dead-letter
 * metadata (reason, dead-lettered-at, replay metadata).
 *
 * The handler enforces:
 *  - **tenant scope** (`api_key_id` matches the authenticated key),
 *  - **dead-lettered status** (the notification is in the `dead_lettered`
 *    state — non-DLQ notifications return 404 from this endpoint).
 *
 * Both checks render as 404 — see
 * `DeadLetteredNotificationNotFoundException` for the
 * information-disclosure rationale.
 */
final class GetDlqController
{
    public function __construct(
        private readonly GetDeadLetteredQueryHandler $handler,
    ) {}

    public function __invoke(Request $request, string $id): JsonResponse
    {
        /** @var ApiKey $apiKey */
        $apiKey = $request->attributes->get('api_key');

        $query = new GetDeadLetteredQuery(
            notificationId: $id,
            apiKeyId: $apiKey->id,
        );

        $notification = ($this->handler)($query);

        return DlqEntryDetailedResource::make($notification)
            ->response();
    }
}
