<?php

declare(strict_types=1);

namespace App\Exceptions;

use EventPulse\Application\Notification\DeadLetter\Exception\DeadLetteredNotificationNotFoundException;
use EventPulse\Application\Notification\Exception\IdempotencyConflictException;
use EventPulse\Domain\Notification\Exception\InvalidNotificationInputException;
use EventPulse\Domain\Notification\Exception\InvalidNotificationTransitionException;
use EventPulse\Domain\Notification\Exception\RecipientChannelMismatchException;
use EventPulse\Domain\WebhookDestination\Exception\WebhookDestinationAlreadyDisabledException;
use EventPulse\Domain\WebhookDestination\Exception\WebhookDestinationNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Renders thrown exceptions into the project-wide JSON error envelope for
 * `/api/*` requests. Intentionally NOT extending `Illuminate\Foundation\
 * Exceptions\Handler`: this is a plain renderer that the bootstrap/app.php
 * `withExceptions()` callback delegates to. Keeping it framework-handler-free
 * means the Laravel 12 reporting/logging configuration in bootstrap/app.php
 * stays in effect — we only override the *render* step for API routes.
 *
 * Returns null when the exception does not match a known mapping; the caller
 * (the `withExceptions` closure) treats null as "fall through to Laravel's
 * default rendering." Returning a JsonResponse short-circuits.
 *
 * Envelope contract:
 *   - `error.code`     — machine-readable error type (stable; clients branch on this).
 *   - `error.message`  — client-facing copy, written for end-users. Stable across
 *                        versions; safe for UIs to display verbatim. Never
 *                        sourced from `Throwable::getMessage()` directly.
 *   - `error.details`  — structured supplementary data. Includes `reason` for
 *                        engineer-facing diagnostic strings (the raw exception
 *                        message), plus error-specific fields like
 *                        `idempotency_key` or `fields` (for ValidationError).
 *
 * The split between `message` and `details.reason` exists because domain
 * exception messages reference internal terminology (state names, VO
 * factory names, field identifiers) that is correct for engineers reading
 * logs but inappropriate as user-facing copy. Keeping the boundary explicit
 * means the surface clients build against does not silently shift when a
 * domain exception's message text is reworded.
 *
 * Day 10 additions:
 *   - `NotFoundHttpException` → 404 `NOT_FOUND` envelope. Previously these
 *     fell through to Laravel's default HTML 404. Every `api/*` route should
 *     return JSON, so we intercept here.
 *   - `ThrottleRequestsException` → 429 `RATE_LIMITED` envelope. Laravel
 *     throws this from its built-in throttle middleware; our own
 *     `ThrottleApiRequests` returns a JsonResponse directly and never
 *     throws. This case covers any route that uses Laravel's built-in
 *     `throttle:n,m` alias (used for health endpoints via `throttle.ip`).
 */
final class ApiExceptionRenderer
{
    public function render(Request $request, Throwable $e): ?JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID');

        // ── Ordering contract ─────────────────────────────────────────────────
        // Domain and application exceptions are checked first (inner layers).
        // HTTP kernel exceptions (NotFoundHttpException, ThrottleRequestsException)
        // are checked last (outer layer). This matters for NotFoundHttpException:
        // domain-specific 404s (DeadLetteredNotificationNotFoundException,
        // WebhookDestinationNotFoundException) must be matched before the generic
        // HTTP 404, even though they all map to the same status code, because
        // the client-facing message is more specific when we know the resource type.
        // Never reorder HTTP kernel exceptions above domain exceptions.

        // ── Laravel HTTP layer ────────────────────────────────────────────────
        if ($e instanceof ValidationException) {
            return $this->envelope(
                code:          'VALIDATION_ERROR',
                message:       'Request body or headers failed validation.',
                status:        Response::HTTP_UNPROCESSABLE_ENTITY,
                correlationId: $correlationId,
                details:       ['fields' => $this->formatValidationErrors($e)],
            );
        }

        if ($e instanceof RecipientChannelMismatchException) {
            // The message on the domain exception describes which value failed
            // (e.g. mentions VO class names). That belongs in the diagnostic,
            // not in `error.message` — the latter is client-facing copy that
            // UIs may show verbatim and that we want stable across versions.
            return $this->envelope(
                code:          'VALIDATION_ERROR',
                message:       'The recipient is not compatible with the requested channel.',
                status:        Response::HTTP_UNPROCESSABLE_ENTITY,
                correlationId: $correlationId,
                details:       ['reason' => $e->getMessage()],
            );
        }

        // ── Application exceptions ───────────────────────────────────────────
        if ($e instanceof IdempotencyConflictException) {
            // 409 specifically: the request is well-formed but conflicts with a
            // previously stored submission under the same Idempotency-Key. The
            // OpenAPI contract names this distinctly from a 422 because the
            // resolution is different — the caller must compare against the
            // original submission, not just fix a malformed field.
            //
            // The exception's own message string-interpolates the key value,
            // which is already in `details.idempotency_key`. Use a stable
            // message and let the caller read the key from where it belongs.
            return $this->envelope(
                code:          'IDEMPOTENCY_CONFLICT',
                message:       'The Idempotency-Key has already been used with a different request body.',
                status:        Response::HTTP_CONFLICT,
                correlationId: $correlationId,
                details:       [
                    'idempotency_key' => $e->idempotencyKey()->toString(),
                ],
            );
        }

        if ($e instanceof InvalidNotificationTransitionException) {
            // The domain message names internal states ("queued → delivered"),
            // which is engineer-facing diagnostic, not API copy. Expose the
            // raw message under `details.reason` for debugging while keeping
            // a stable client-facing message.
            return $this->envelope(
                code:          'INVALID_STATE',
                message:       'The notification cannot perform the requested operation in its current state.',
                status:        Response::HTTP_CONFLICT,
                correlationId: $correlationId,
                details:       ['reason' => $e->getMessage()],
            );
        }

        if ($e instanceof InvalidNotificationInputException) {
            // Domain validation messages are written for engineers reading
            // logs (they may reference VO factory names, internal field
            // identifiers, etc.). The diagnostic moves to `details.reason`.
            return $this->envelope(
                code:          'VALIDATION_ERROR',
                message:       'The request data violates a notification domain rule.',
                status:        Response::HTTP_UNPROCESSABLE_ENTITY,
                correlationId: $correlationId,
                details:       ['reason' => $e->getMessage()],
            );
        }

        // ── Domain exceptions ─────────────────────────────────────────────────
        if ($e instanceof DeadLetteredNotificationNotFoundException) {
            // "Not found" and "not yours" are both 404 by design — see the
            // exception's docblock for the information-disclosure rationale.
            return $this->envelope(
                code:          'NOT_FOUND',
                message:       'The requested dead-letter entry was not found.',
                status:        Response::HTTP_NOT_FOUND,
                correlationId: $correlationId,
            );
        }

        if ($e instanceof WebhookDestinationNotFoundException) {
            // Same information-disclosure reasoning: unknown id and
            // wrong-tenant id are both rendered as 404.
            return $this->envelope(
                code:          'NOT_FOUND',
                message:       'The requested webhook destination was not found.',
                status:        Response::HTTP_NOT_FOUND,
                correlationId: $correlationId,
            );
        }

        if ($e instanceof WebhookDestinationAlreadyDisabledException) {
            return $this->envelope(
                code:          'ALREADY_DISABLED',
                message:       'The webhook destination is already disabled.',
                status:        Response::HTTP_CONFLICT,
                correlationId: $correlationId,
            );
        }

        // ── HTTP kernel exceptions (must come after all domain exceptions) ─────
        if ($e instanceof NotFoundHttpException) {
            // Laravel throws this when no route matches or a route model
            // binding fails. The default handler returns HTML; every API
            // route must return JSON. We don't expose the raw message
            // because it contains route and binding internal details.
            return $this->envelope(
                code:          'NOT_FOUND',
                message:       'The requested resource was not found.',
                status:        Response::HTTP_NOT_FOUND,
                correlationId: $correlationId,
            );
        }

        if ($e instanceof ThrottleRequestsException) {
            // Laravel's built-in throttle middleware throws this. Our own
            // `ThrottleApiRequests` returns a JsonResponse directly, but
            // `ThrottleIpRequests` delegates to the cache-backed limiter and
            // may cause this to be thrown on some edge paths. Intercept here
            // so every 429 from any source uses our error envelope.
            $retryAfter = $e->getHeaders()['Retry-After'] ?? null;

            return $this->envelope(
                code:          'RATE_LIMITED',
                message:       'You have exceeded the rate limit. Please slow down.',
                status:        Response::HTTP_TOO_MANY_REQUESTS,
                correlationId: $correlationId,
                details:       is_numeric($retryAfter)
                    ? ['retry_after' => (int) $retryAfter]
                    : null,
            );
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $details
     */
    private function envelope(
        string $code,
        string $message,
        int $status,
        ?string $correlationId,
        ?array $details = null,
    ): JsonResponse {
        $payload = [
            'error' => array_filter(
                [
                    'code'    => $code,
                    'message' => $message,
                    'details' => $details,
                ],
                static fn(mixed $v): bool => $v !== null,
            ),
        ];

        if ($correlationId !== null && $correlationId !== '') {
            $payload['correlation_id'] = $correlationId;
        }

        return new JsonResponse($payload, $status);
    }

    /**
     * @return list<array{path: string, message: string}>
     */
    private function formatValidationErrors(ValidationException $e): array
    {
        $fields = [];

        foreach ($e->errors() as $key => $messages) {
            $path = '/' . str_replace('.', '/', (string) $key);

            foreach ((array) $messages as $message) {
                $fields[] = [
                    'path'    => $path,
                    'message' => $message,
                ];
            }
        }

        return $fields;
    }
}
