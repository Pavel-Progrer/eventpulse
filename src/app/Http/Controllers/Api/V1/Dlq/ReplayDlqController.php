<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Dlq;

use App\Http\Resources\Api\V1\Notification\NotificationResource;
use App\Models\ApiKey;
use EventPulse\Application\Notification\Command\ReplayDeadLetteredCommand;
use EventPulse\Application\Notification\Command\ReplayDeadLetteredHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /api/v1/dlq/{id}/replay` — create a new notification from a
 * dead-lettered one.
 *
 * Required scope: `dlq:replay`.
 * Required header: `Idempotency-Key` (UUID format, enforced by middleware).
 *
 * Returns 202 with the new notification's acceptance shape. The `X-Correlation-ID`
 * header echoes the correlation id used for the new notification, exactly as
 * `SubmitNotificationController` does — callers can use it to trace the replay
 * through the dispatch flow.
 *
 * 409 is returned when the entry was already replayed by a different
 * idempotency key (`AlreadyReplayedException`). The same idempotency key used
 * in the original replay returns 202 again (idempotent path in the handler).
 */
final class ReplayDlqController
{
    public function __construct(
        private readonly ReplayDeadLetteredHandler $handler,
    ) {}

    public function __invoke(Request $request, string $id): JsonResponse
    {
        /** @var ApiKey $apiKey */
        $apiKey = $request->attributes->get('api_key');

        $notification = ($this->handler)(new ReplayDeadLetteredCommand(
            notificationId: $id,
            apiKeyId:       (string) $apiKey->id,
            idempotencyKey: (string) $request->header('Idempotency-Key'),
            correlationId:  $request->header('X-Correlation-ID'),
        ));

        $response = NotificationResource::make($notification)
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);

        $response->headers->set('X-Correlation-ID', $notification->correlationId()->toString());

        return $response;
    }
}
