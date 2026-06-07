<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\WebhookDestination;

use EventPulse\Application\WebhookDestination\Query\ListWebhookDestinationsResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Renders the paginated response for `GET /api/v1/webhook-destinations`.
 *
 * Follows the same envelope shape as `DlqEntryPageResource`:
 * ```json
 * {
 *   "data": [ { ...destination... }, ... ],
 *   "meta": { "next_cursor": "..." | null }
 * }
 * ```
 *
 * @property-read ListWebhookDestinationsResult $resource
 */
final class PaginatedWebhookDestinationsResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var ListWebhookDestinationsResult $result */
        $result = $this->resource;

        return [
            'data' => array_map(
                fn ($d): array => (new WebhookDestinationResource($d))->toArray($request),
                $result->destinations,
            ),
            'meta' => [
                'next_cursor' => $result->nextCursor,
            ],
        ];
    }
}
