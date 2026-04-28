<?php

declare(strict_types=1);

namespace App\Exceptions;

use EventPulse\Application\Notification\Exception\IdempotencyConflictException;
use EventPulse\Domain\Notification\Exception\InvalidNotificationInputException;
use EventPulse\Domain\Notification\Exception\InvalidNotificationTransitionException;
use EventPulse\Domain\Notification\Exception\RecipientChannelMismatchException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
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
 */
final class ApiExceptionRenderer
{
    public function render(Request $request, Throwable $e): ?JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID');

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