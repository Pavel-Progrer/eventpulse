<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves an Authorization header into an `ApiKey` model and attaches it to
 * the request.
 *
 * Day 3 scope: Bearer-token-only authentication. The OpenAPI specifies HMAC
 * request signing on top of the Bearer token (`X-EventPulse-Signature` +
 * `X-EventPulse-Timestamp`); that is Day 9's work. The middleware is named
 * and structured so adding HMAC verification later is a single new check
 * inside the same class — not a redesign.
 *
 * Failure modes:
 *  - Missing or malformed `Authorization` header → 401.
 *  - Identifier does not match any row → 401.
 *  - Key is not active (revoked / rotated) → 401.
 *
 * Returning 401 (not 403) for "no key" and "key not recognised" is deliberate:
 * 401 means "we don't know who you are", 403 means "we know who you are but
 * you can't do this." Scope failures (handled in `RequireScope`) are 403.
 */
final class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): mixed
    {
        $header = $request->header('Authorization', '');

        if (! is_string($header) || $header === '') {
            return $this->unauthorized('Missing Authorization header.');
        }

        if (! str_starts_with($header, 'Bearer ')) {
            return $this->unauthorized('Authorization header must use Bearer scheme.');
        }

        $identifier = trim(substr($header, 7));
        if ($identifier === '') {
            return $this->unauthorized('Bearer token is empty.');
        }

        $apiKey = ApiKey::query()
            ->where('identifier', $identifier)
            ->first();

        if ($apiKey === null) {
            return $this->unauthorized('Unknown API key.');
        }

        if (! $apiKey->isActive()) {
            return $this->unauthorized('API key is not active.');
        }

        // Attach to the request so controllers and downstream middleware
        // (`RequireScope`) can read it without re-querying.
        $request->attributes->set('api_key', $apiKey);

        return $next($request);
    }

    private function unauthorized(string $message): JsonResponse
    {
        return new JsonResponse(
            [
                'error' => [
                    'code' => 'UNAUTHORIZED',
                    'message' => $message,
                ],
            ],
            Response::HTTP_UNAUTHORIZED,
        );
    }
}
