<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\SubmitNotificationRequest;
use App\Http\Resources\Api\V1\NotificationAcceptedResource;
use App\Models\ApiKey;
use EventPulse\Application\Notification\Command\SubmitNotificationCommand;
use EventPulse\Application\Notification\Command\SubmitNotificationHandler;
use EventPulse\Domain\Notification\Enum\Channel;
use EventPulse\Domain\Notification\Enum\Priority;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /api/v1/notifications` — accept a notification dispatch request.
 *
 * The controller is intentionally thin (per ADR-0003 §1):
 *   - Pull the authenticated `ApiKey` off the request (placed there by
 *     `AuthenticateApiKey` middleware).
 *   - Map validated input from the FormRequest into a
 *     `SubmitNotificationCommand` (resolving enums, mapping API field names
 *     `body_text`/`body_html` to domain field names `text`/`html`).
 *   - Invoke the handler.
 *   - Wrap the result in a `NotificationAcceptedResource` with the right
 *     HTTP status (202 for fresh, 200 for idempotent replay).
 *
 * Domain or persistence calls do not happen here directly.
 */
final class SubmitNotificationController
{
    public function __construct(
        private readonly SubmitNotificationHandler $handler,
    ) {}

    public function __invoke(SubmitNotificationRequest $request): JsonResponse
    {
        /** @var ApiKey $apiKey */
        $apiKey = $request->attributes->get('api_key');

        $command = new SubmitNotificationCommand(
            channel:        Channel::from($request->validated('channel')),
            recipient:      (string) $request->validated('recipient'),
            payload:        $this->mapPayloadForDomain(
                $request->validated('channel'),
                $request->validated('payload', []),
            ),
            priority:       Priority::from($request->validated('priority', 'normal')),
            idempotencyKey: (string) $request->header('Idempotency-Key'),
            apiKeyId:       (string) $apiKey->id,
            correlationId:  $request->header('X-Correlation-ID'),
        );

        $result = ($this->handler)($command);

        $response = NotificationAcceptedResource::make($result)
            ->response()
            ->setStatusCode(
                $result->wasIdempotentReplay
                    ? Response::HTTP_OK
                    : Response::HTTP_ACCEPTED,
            );

        // Echo the correlation id used (caller-supplied or generated). The
        // header is the canonical place for cross-system tracing; the JSON
        // body carries it too for clients that ignore headers.
        $response->headers->set('X-Correlation-ID', $result->correlationId->toString());

        return $response;
    }

    /**
     * Translate API-level payload keys to domain-level keys.
     *
     * The OpenAPI contract uses `body_text` / `body_html` for email payloads;
     * the domain `NotificationPayload::validateEmail()` checks `text` / `html`.
     * The mapping is one-directional and lives here, at the boundary, so the
     * domain stays free of HTTP-specific naming and the API is free to evolve
     * its names without rewriting domain rules.
     *
     * @param  array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function mapPayloadForDomain(string $channel, array $payload): array
    {
        if ($channel !== 'email') {
            return $payload;
        }

        $mapped = [];
        if (isset($payload['subject'])) {
            $mapped['subject'] = $payload['subject'];
        }
        if (isset($payload['body_text'])) {
            $mapped['text'] = $payload['body_text'];
        }
        if (isset($payload['body_html'])) {
            $mapped['html'] = $payload['body_html'];
        }
        if (isset($payload['reply_to'])) {
            $mapped['reply_to'] = $payload['reply_to'];
        }

        return $mapped;
    }
}
