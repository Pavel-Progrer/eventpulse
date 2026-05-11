<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\WebhookDestination;

use App\Models\ApiKey;
use EventPulse\Application\WebhookDestination\Command\DisableWebhookDestinationCommand;
use EventPulse\Application\WebhookDestination\Command\DisableWebhookDestinationHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `DELETE /api/v1/webhook-destinations/{id}`
 *
 * Disables a webhook destination. Does not delete it — historical notifications
 * referencing the destination are preserved (domain.md §5.2.5).
 *
 * The route parameter `{id}` is the destination's UUID. The middleware has
 * already authenticated the caller; the handler enforces tenant isolation
 * (only destinations owned by this API key can be disabled).
 *
 * Returns `204 No Content` on success.
 * Returns `404 Not Found` when the destination does not exist or belongs to
 *   a different API key.
 * Returns `409 Conflict` when the destination is already disabled.
 */
final class DisableWebhookDestinationController
{
    public function __construct(
        private readonly DisableWebhookDestinationHandler $handler,
    ) {}

    public function __invoke(Request $request, string $id): JsonResponse
    {
        /** @var ApiKey $apiKey */
        $apiKey = $request->attributes->get('api_key');

        $command = new DisableWebhookDestinationCommand(
            destinationId: $id,
            apiKeyId:      (string) $apiKey->id,
            correlationId: $request->header('X-Correlation-ID'),
        );

        ($this->handler)($command);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
