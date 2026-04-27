<?php

declare(strict_types=1);

namespace App\Exceptions;

use EventPulse\Domain\Notification\Exception\InvalidNotificationInputException;
use EventPulse\Domain\Notification\Exception\InvalidNotificationTransitionException;
use EventPulse\Domain\Notification\Exception\RecipientChannelMismatchException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
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
                message:       $e->getMessage(),
                status:        Response::HTTP_UNPROCESSABLE_ENTITY,
                correlationId: $correlationId,
            );
        }

        if ($e instanceof InvalidNotificationTransitionException) {
            return $this->envelope(
                code:          'INVALID_STATE',
                message:       $e->getMessage(),
                status:        Response::HTTP_CONFLICT,
                correlationId: $correlationId,
            );
        }

        if ($e instanceof InvalidNotificationInputException) {
            return $this->envelope(
                code:          'VALIDATION_ERROR',
                message:       $e->getMessage(),
                status:        Response::HTTP_UNPROCESSABLE_ENTITY,
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
