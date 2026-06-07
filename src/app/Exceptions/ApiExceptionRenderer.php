<?php

declare(strict_types=1);

namespace App\Exceptions;

use EventPulse\Application\Notification\Command\AlreadyReplayedException;
use EventPulse\Application\Notification\DeadLetter\Exception\DeadLetteredNotificationNotFoundException;
use EventPulse\Application\Notification\Exception\IdempotencyConflictException;
use EventPulse\Application\Notification\Query\NotificationNotFoundException;
use EventPulse\Domain\Notification\Exception\InvalidNotificationInputException;
use EventPulse\Domain\Notification\Exception\InvalidNotificationTransitionException;
use EventPulse\Domain\Notification\Exception\RecipientChannelMismatchException;
use EventPulse\Domain\WebhookDestination\Exception\WebhookDestinationAlreadyDisabledException;
use EventPulse\Domain\WebhookDestination\Exception\WebhookDestinationNotFoundException;
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
 *   - `error.code`    — machine-readable error type (stable; clients branch on this).
 *   - `error.message` — client-facing copy, stable across versions, safe for UIs to
 *                       display verbatim. Never sourced from `Throwable::getMessage()`
 *                       directly — domain messages reference internal terminology.
 *   - `error.details` — structured supplementary data: `reason` for engineer-facing
 *                       diagnostics, plus error-specific fields like `idempotency_key`
 *                       or `fields` for validation errors.
 *
 * Exception → HTTP status mapping (full inventory):
 *   ValidationException                       → 422 VALIDATION_ERROR
 *   RecipientChannelMismatchException         → 422 VALIDATION_ERROR
 *   InvalidNotificationInputException         → 422 VALIDATION_ERROR
 *   IdempotencyConflictException              → 409 IDEMPOTENCY_CONFLICT
 *   AlreadyReplayedException                  → 409 ALREADY_REPLAYED
 *   WebhookDestinationAlreadyDisabledException → 409 ALREADY_DISABLED
 *   InvalidNotificationTransitionException    → 409 INVALID_STATE
 *   NotificationNotFoundException             → 404 NOT_FOUND
 *   DeadLetteredNotificationNotFoundException → 404 NOT_FOUND
 *   WebhookDestinationNotFoundException       → 404 NOT_FOUND
 *   NotFoundHttpException                     → 404 NOT_FOUND  (unknown routes)
 */
final class ApiExceptionRenderer
{
    public function render(Request $request, Throwable $e): ?JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID');

        if ($e instanceof ValidationException) {
            return $this->envelope(
                code: 'VALIDATION_ERROR',
                message: 'Request body or headers failed validation.',
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
                correlationId: $correlationId,
                details: ['fields' => $this->formatValidationErrors($e)],
            );
        }

        if ($e instanceof RecipientChannelMismatchException) {
            return $this->envelope(
                code: 'VALIDATION_ERROR',
                message: 'The recipient is not compatible with the requested channel.',
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
                correlationId: $correlationId,
                details: ['reason' => $e->getMessage()],
            );
        }

        if ($e instanceof InvalidNotificationInputException) {
            return $this->envelope(
                code: 'VALIDATION_ERROR',
                message: 'The request data violates a notification domain rule.',
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
                correlationId: $correlationId,
                details: ['reason' => $e->getMessage()],
            );
        }

        if ($e instanceof IdempotencyConflictException) {
            return $this->envelope(
                code: 'IDEMPOTENCY_CONFLICT',
                message: 'The Idempotency-Key has already been used with a different request body.',
                status: Response::HTTP_CONFLICT,
                correlationId: $correlationId,
                details: ['idempotency_key' => $e->idempotencyKey()->toString()],
            );
        }

        if ($e instanceof AlreadyReplayedException) {
            return $this->envelope(
                code: 'ALREADY_REPLAYED',
                message: 'This DLQ entry has already been replayed.',
                status: Response::HTTP_CONFLICT,
                correlationId: $correlationId,
            );
        }

        if ($e instanceof WebhookDestinationAlreadyDisabledException) {
            return $this->envelope(
                code: 'ALREADY_DISABLED',
                message: 'This webhook destination is already disabled.',
                status: Response::HTTP_CONFLICT,
                correlationId: $correlationId,
            );
        }

        if ($e instanceof InvalidNotificationTransitionException) {
            return $this->envelope(
                code: 'INVALID_STATE',
                message: 'The notification cannot perform the requested operation in its current state.',
                status: Response::HTTP_CONFLICT,
                correlationId: $correlationId,
                details: ['reason' => $e->getMessage()],
            );
        }

        // All "not found" cases collapse to the same 404 envelope — whether the
        // id is unknown, belongs to a different tenant, or is a missing HTTP
        // route. Uniform 404 avoids information disclosure (tenant enumeration,
        // route enumeration).
        if ($e instanceof NotificationNotFoundException
            || $e instanceof DeadLetteredNotificationNotFoundException
            || $e instanceof WebhookDestinationNotFoundException
            || $e instanceof NotFoundHttpException
        ) {
            return $this->envelope(
                code: 'NOT_FOUND',
                message: 'The requested resource was not found.',
                status: Response::HTTP_NOT_FOUND,
                correlationId: $correlationId,
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $details
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
                    'code' => $code,
                    'message' => $message,
                    'details' => $details,
                ],
                static fn (mixed $v): bool => $v !== null,
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
            $path = '/'.str_replace('.', '/', (string) $key);

            foreach ((array) $messages as $message) {
                $fields[] = [
                    'path' => $path,
                    'message' => $message,
                ];
            }
        }

        return $fields;
    }
}
