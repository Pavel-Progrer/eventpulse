<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces that the authenticated `ApiKey` carries a required scope.
 *
 * Used as `scope:notifications:write`, `scope:notifications:read`, etc., in
 * the route definition. Runs *after* `AuthenticateApiKey`, so the api key
 * is guaranteed to be present in `request->attributes`.
 *
 * Returns 403 (not 401) when the scope is missing — see `AuthenticateApiKey`
 * for why this distinction matters.
 *
 * The list of valid scopes is encoded in `ApiKey::hasScope()` (which honours
 * the `admin` umbrella). This middleware is purely a presence check; it
 * trusts the model.
 */
//  TODO(Day 10) add rate limiter
final class RequireScope
{
    public function handle(Request $request, Closure $next, string $requiredScope): mixed
    {
        /** @var ApiKey|null $apiKey */
        $apiKey = $request->attributes->get('api_key');

        // Defensive: if the auth middleware did not run, fail closed.
        // This is a programming error (route forgot `auth.api-key`) but
        // returning 500 here would leak less information than 403.
        if (! $apiKey instanceof ApiKey) {
            return new JsonResponse(
                [
                    'error' => [
                        'code' => 'FORBIDDEN',
                        'message' => 'Authentication required.',
                    ],
                ],
                Response::HTTP_FORBIDDEN,
            );
        }

        if (! $apiKey->hasScope($requiredScope)) {
            return new JsonResponse(
                [
                    'error' => [
                        'code' => 'FORBIDDEN',
                        'message' => sprintf('Missing required scope: %s', $requiredScope),
                        'details' => ['required_scope' => $requiredScope],
                    ],
                ],
                Response::HTTP_FORBIDDEN,
            );
        }

        return $next($request);
    }
}
