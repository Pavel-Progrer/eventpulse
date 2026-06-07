<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\WebhookDestination;

use EventPulse\Domain\WebhookDestination\Aggregate\WebhookDestination;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Renders a `WebhookDestination` aggregate as the OpenAPI `WebhookDestination`
 * schema — without the `secret` field (the secret is never returned after
 * creation; see `WebhookDestinationCreatedResource` for the one-time shape).
 *
 * `$wrap = null` so the resource doesn't inject an extra `data` envelope.
 * The list resource wraps at the page level; the controller wraps the single
 * item via `->response()` directly.
 *
 * @property-read WebhookDestination $resource
 */
final class WebhookDestinationResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var WebhookDestination $d */
        $d = $this->resource;

        return [
            'id' => $d->id()->toString(),
            'url' => $d->url(),
            'name' => $d->name(),
            'status' => $d->status()->value,
            'created_at' => $d->createdAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
