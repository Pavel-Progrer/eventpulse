<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Dlq;

use App\Models\ApiKey;
use EventPulse\Application\Notification\Command\DiscardDeadLetteredCommand;
use EventPulse\Application\Notification\Command\DiscardDeadLetteredHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `DELETE /api/v1/dlq/{id}` — discard a dead-lettered notification from the
 * active DLQ view.
 *
 * Required scope: `dlq:replay` (same as replay — both are write operations
 * over the DLQ; the spec groups them under one permission gate).
 *
 * Returns 204 No Content on success. Idempotent: discarding an already-discarded
 * entry also returns 204.
 */
final class DiscardDlqController
{
    public function __construct(
        private readonly DiscardDeadLetteredHandler $handler,
    ) {}

    public function __invoke(Request $request, string $id): JsonResponse
    {
        /** @var ApiKey $apiKey */
        $apiKey = $request->attributes->get('api_key');

        ($this->handler)(new DiscardDeadLetteredCommand(
            notificationId: $id,
            apiKeyId:       (string) $apiKey->id,
        ));

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
