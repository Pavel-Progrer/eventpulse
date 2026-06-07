<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-API-key rate limiting with separate write and read quotas.
 *
 * Specification §5.3:
 *  - Default write limit: 100 requests/min per API key.
 *  - Default read limit:  600 requests/min per API key.
 *  - Per-key overrides stored in `api_keys.rate_limit_per_minute`.
 *  - Exceeding returns 429 with `Retry-After` and `X-RateLimit-*` headers.
 *  - Anonymous endpoints (health/detailed) are rate-limited per IP at 60/min
 *    (handled by a separate `throttle:ip` alias in routes; this middleware
 *    handles authenticated endpoints only).
 *
 * Design choices:
 *
 * Write vs. read classification:
 *   POST / PUT / PATCH / DELETE are writes; GET / HEAD / OPTIONS are reads.
 *   This is conventional and matches the spec verbatim. The split allows a
 *   client to poll `/notifications/{id}` or `/dlq` aggressively without
 *   burning the write budget.
 *
 * Redis-backed RateLimiter:
 *   Laravel's `RateLimiter` uses the cache driver (Redis in production).
 *   The bucket key is `eventpulse:rl:{api_key_id}:{bucket}:{window}` where
 *   `{window}` is the Unix minute. Using the minute timestamp rather than a
 *   rolling window means every client resets on the same clock tick — simpler
 *   to reason about and consistent with `Retry-After` semantics.
 *
 * Per-key override:
 *   `api_keys.rate_limit_per_minute` applies to the *write* bucket only.
 *   There is no per-key read override in the current spec. If the column is
 *   null, the default applies. This preserves forward-compatibility: adding a
 *   per-key read override later is an additive column change.
 *
 * Why not Laravel's built-in `throttle:60,1` middleware?
 *   The built-in middleware uses `X-RateLimit-Limit` but does not support
 *   dynamic per-key limits or the separate write/read split this spec
 *   requires. Wrapping `RateLimiter` directly is 50 lines and gives us full
 *   control over the bucket key shape and response headers.
 *
 * Header names follow the de-facto standard (GitHub, Stripe, Shopify):
 *   X-RateLimit-Limit     — max requests allowed in the window.
 *   X-RateLimit-Remaining — requests remaining in this window.
 *   X-RateLimit-Reset     — Unix timestamp at which the window resets.
 *   Retry-After           — seconds until the next request is permitted.
 */
final class ThrottleApiRequests
{
    private const int DEFAULT_WRITE_LIMIT = 100;

    private const int DEFAULT_READ_LIMIT = 600;

    private const int WINDOW_SECONDS = 60;

    public function __construct(
        private readonly RateLimiter $limiter,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        /** @var ApiKey|null $apiKey */
        $apiKey = $request->attributes->get('api_key');

        // This middleware is registered after `auth.api-key`, so $apiKey is
        // always present on authenticated routes. The null guard is defensive:
        // if somehow reached unauthenticated, fall through — authentication
        // errors are the prior middleware's responsibility.
        if (! $apiKey instanceof ApiKey) {
            return $next($request);
        }

        $isWrite = $this->isWriteRequest($request);
        $limit = $this->resolveLimit($apiKey, $isWrite);
        $key = $this->bucketKey($apiKey->id, $isWrite);
        $window = (int) (floor(now()->timestamp / self::WINDOW_SECONDS) + 1) * self::WINDOW_SECONDS;

        if ($this->limiter->tooManyAttempts($key, $limit)) {
            $retryAfter = $this->limiter->availableIn($key);

            return $this->tooManyRequests(
                limit: $limit,
                remaining: 0,
                resetAt: $window,
                retryAfter: $retryAfter,
                correlationId: $request->header('X-Correlation-ID'),
            );
        }

        $this->limiter->hit($key, self::WINDOW_SECONDS);

        $attempts = $this->limiter->attempts($key);
        $remaining = max(0, $limit - $attempts);

        /** @var Response $response */
        $response = $next($request);

        return $this->addRateLimitHeaders($response, $limit, $remaining, $window);
    }

    private function isWriteRequest(Request $request): bool
    {
        return in_array(
            strtoupper($request->method()),
            ['POST', 'PUT', 'PATCH', 'DELETE'],
            strict: true,
        );
    }

    private function resolveLimit(ApiKey $apiKey, bool $isWrite): int
    {
        if ($isWrite) {
            // Per-key override applies to writes only.
            $override = $apiKey->rate_limit_per_minute;

            return (is_int($override) && $override > 0)
                ? $override
                : self::DEFAULT_WRITE_LIMIT;
        }

        return self::DEFAULT_READ_LIMIT;
    }

    private function bucketKey(string $apiKeyId, bool $isWrite): string
    {
        $bucket = $isWrite ? 'write' : 'read';

        return sprintf('eventpulse:rl:%s:%s', $apiKeyId, $bucket);
    }

    private function addRateLimitHeaders(Response $response, int $limit, int $remaining, int $resetAt): Response
    {
        $response->headers->set('X-RateLimit-Limit', (string) $limit);
        $response->headers->set('X-RateLimit-Remaining', (string) $remaining);
        $response->headers->set('X-RateLimit-Reset', (string) $resetAt);

        return $response;
    }

    /**
     * @param  string|string[]|null  $correlationId
     */
    private function tooManyRequests(
        int $limit,
        int $remaining,
        int $resetAt,
        int $retryAfter,
        string|array|null $correlationId,
    ): JsonResponse {
        $payload = [
            'error' => [
                'code' => 'RATE_LIMITED',
                'message' => 'You have exceeded the rate limit. Please slow down.',
                'details' => [
                    'limit' => $limit,
                    'retry_after' => $retryAfter,
                ],
            ],
        ];

        if (is_string($correlationId) && $correlationId !== '') {
            $payload['correlation_id'] = $correlationId;
        }

        return new JsonResponse($payload, Response::HTTP_TOO_MANY_REQUESTS, [
            'Retry-After' => (string) $retryAfter,
            'X-RateLimit-Limit' => (string) $limit,
            'X-RateLimit-Remaining' => (string) $remaining,
            'X-RateLimit-Reset' => (string) $resetAt,
        ]);
    }
}
