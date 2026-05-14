<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\WebhookDestination;

use App\Http\Requests\Api\V1\WebhookDestination\RegisterWebhookDestinationRequest;
use App\Http\Resources\Api\V1\WebhookDestination\WebhookDestinationCreatedResource;
use App\Models\ApiKey;
use EventPulse\Application\WebhookDestination\Command\RegisterWebhookDestinationCommand;
use EventPulse\Application\WebhookDestination\Command\RegisterWebhookDestinationHandler;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/webhook-destinations`
 *
 * Registers a new webhook destination (URL + signing secret) under the
 * authenticated API key. The returned `id` is used as `recipient` in
 * subsequent `POST /api/v1/notifications` webhook requests.
 *
 * The response includes the plaintext secret exactly once. The operator must
 * store it; the API will never return it again. Subsequent reads return only
 * the destination metadata (url, name, status).
 *
 * Returns `201 Created`.
 */
final class RegisterWebhookDestinationController
{
    public function __construct(
        private readonly RegisterWebhookDestinationHandler $handler,
    ) {}

    public function __invoke(RegisterWebhookDestinationRequest $request): JsonResponse
    {
        /** @var ApiKey $apiKey */
        $apiKey = $request->attributes->get('api_key');

        $command = new RegisterWebhookDestinationCommand(
            apiKeyId:      (string) $apiKey->id,
            url:           (string) $request->validated('url'),
            secret:        (string) $request->validated('secret'),
            name:          $request->validated('name'),
            correlationId: $request->header('X-Correlation-ID'),
        );

        $result = ($this->handler)($command);

        return WebhookDestinationCreatedResource::make($result)
            ->response()
            ->setStatusCode(201);
    }
}
