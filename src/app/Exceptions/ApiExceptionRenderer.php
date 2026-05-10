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
 * Day 8 backfill (missed at the time):
 *  - `DeadLetteredNotificationNotFoundException` → 404 NOT_FOUND
 *
 * Day 9 additions:
 *  - `WebhookDestinationNotFoundException` → 404 NOT_FOUND
 *  - `WebhookDestinationAlreadyDisabledException` → 409 ALREADY_DISABLED
 *
 * Envelope contract:
 *   - `error.code`     — machine-readable error type (stable; clients branch on this).
 *   - `error.message`  — client-facing copy, written for end-users.
 *   - `error.details`  — structured supplementary data.
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
            return $this->envelope(
                code:          'VALIDATION_ERROR',
                message:       'The recipient is not compatible with the requested channel.',
                status:        Response::HTTP_UNPROCESSABLE_ENTITY,
                correlationId: $correlationId,
                details:       ['reason' => $e->getMessage()],
            );
        }

        if ($e instanceof IdempotencyConflictException) {
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
            return $this->envelope(
                code:          'INVALID_STATE',
                message:       'The notification cannot perform the requested operation in its current state.',
                status:        Response::HTTP_CONFLICT,
                correlationId: $correlationId,
                details:       ['reason' => $e->getMessage()],
            );
        }

        if ($e instanceof InvalidNotificationInputException) {
            return $this->envelope(
                code:          'VALIDATION_ERROR',
                message:       'The request data violates a notification domain rule.',
                status:        Response::HTTP_UNPROCESSABLE_ENTITY,
                correlationId: $correlationId,
                details:       ['reason' => $e->getMessage()],
            );
        }

        if ($e instanceof DeadLetteredNotificationNotFoundException) {
            // Covers: id not found, cross-tenant id, and notification exists
            // but is not dead-lettered. All three render as 404 — distinguishing
            // them would leak information about which notification ids exist for
            // other tenants, or expose the internal status of a notification the
            // caller does not have permission to read via this endpoint.
            return $this->envelope(
                code:          'NOT_FOUND',
                message:       'The requested dead-letter entry does not exist or is not accessible.',
                status:        Response::HTTP_NOT_FOUND,
                correlationId: $correlationId,
            );
        }

        if ($e instanceof WebhookDestinationNotFoundException) {
            return $this->envelope(
                code:          'NOT_FOUND',
                message:       'The webhook destination does not exist or is not accessible.',
                status:        Response::HTTP_NOT_FOUND,
                correlationId: $correlationId,
            );
        }

        if ($e instanceof WebhookDestinationAlreadyDisabledException) {
            return $this->envelope(
                code:          'ALREADY_DISABLED',
                message:       'This webhook destination is already disabled.',
                status:        Response::HTTP_CONFLICT,
                correlationId: $correlationId,
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
