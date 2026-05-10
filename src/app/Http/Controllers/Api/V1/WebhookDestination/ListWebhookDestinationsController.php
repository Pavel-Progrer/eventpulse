<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\WebhookDestination;

use App\Http\Requests\Api\V1\WebhookDestination\ListWebhookDestinationsRequest;
use App\Http\Resources\Api\V1\WebhookDestination\PaginatedWebhookDestinationsResource;
use App\Models\ApiKey;
use EventPulse\Application\WebhookDestination\Query\ListWebhookDestinationsQuery;
use EventPulse\Application\WebhookDestination\Query\ListWebhookDestinationsQueryHandler;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/webhook-destinations`
 *
 * Returns a paginated list of webhook destinations owned by the authenticated
 * API key. Destinations are ordered newest-first with cursor-based pagination.
 *
 * Both active and disabled destinations are returned. Callers filter by status
 * in their own display layer; the API exposes the full history so operators can
 * audit which destinations exist and which were disabled.
 *
 * Returns `200 OK`.
 */
final class ListWebhookDestinationsController
{
    public function __construct(
        private readonly ListWebhookDestinationsQueryHandler $handler,
    ) {}

    public function __invoke(ListWebhookDestinationsRequest $request): JsonResponse
    {
        /** @var ApiKey $apiKey */
        $apiKey = $request->attributes->get('api_key');

        $query = new ListWebhookDestinationsQuery(
            apiKeyId: (string) $apiKey->id,
            limit:    (int) $request->validated('limit', 20),
            afterId:  $request->validated('cursor'),
        );

        $result = ($this->handler)($query);

        return PaginatedWebhookDestinationsResource::make($result)
            ->response();
    }
}
