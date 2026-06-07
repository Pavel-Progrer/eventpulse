<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\WebhookDestination;

use EventPulse\Application\WebhookDestination\Command\RegisterWebhookDestinationResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Renders the response to `POST /api/v1/webhook-destinations`.
 *
 * This is the **only** resource that includes the plaintext `secret` field.
 * Per the OpenAPI spec and domain.md §5.2.3, the secret is returned exactly
 * once at creation time and never again — the operator must capture it at
 * this moment. Subsequent reads (`GET /webhook-destinations/{id}` and the
 * list endpoint) use `WebhookDestinationResource`, which omits the secret.
 *
 * The wrapped object is a `RegisterWebhookDestinationResult` DTO — not the
 * domain aggregate — because the aggregate does not carry the secret.
 *
 * @property-read RegisterWebhookDestinationResult $resource
 */
final class WebhookDestinationCreatedResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var RegisterWebhookDestinationResult $result */
        $result = $this->resource;

        return [
            'id' => $result->id->toString(),
            'url' => $result->url,
            'name' => $result->name,
            'status' => $result->status->value,
            'secret' => $result->secret,
            'created_at' => $result->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
