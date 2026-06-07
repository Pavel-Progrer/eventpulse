<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * IP-based rate limiting for unauthenticated endpoints.
 *
 * Specification §5.3: "Anonymous endpoints (health/detailed) rate-limited
 * per IP at 60/min."
 *
 * Separated from `ThrottleApiRequests` because the throttle key, limit, and
 * response semantics differ:
 *  - Key is the client IP, not an API key id.
 *  - Limit is a fixed 60/min with no per-client override.
 *  - Response is still the project-wide error envelope.
 *
 * Why not Laravel's `throttle:60,1`?
 *   `throttle:60,1` uses Laravel's default rate-limiter key which includes
 *   the route signature. That means `/health` and `/health/detailed` share
 *   a pool per client IP, which is the desired behavior — but the built-in
 *   middleware returns an HTML/redirect response on the web guard and the
 *   JSON guard's response doesn't match our error envelope. Implementing
 *   the 30-line version explicitly is cleaner than patching the built-in.
 *
 * Middleware alias: `throttle.ip` (registered in EventPulseServiceProvider).
 */
final class ThrottleIpRequests
{
    private const int LIMIT = 60;

    private const int WINDOW_SECONDS = 60;

    public function __construct(
        private readonly RateLimiter $limiter,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        // `ip()` returns null only when the request has no REMOTE_ADDR — possible
        // in CLI-dispatched requests but not in normal HTTP context. Falling back
        // to 'unknown' means all such requests share one bucket, which is acceptable
        // here: the `api` middleware group guarantees a real HTTP context for every
        // route this middleware is attached to.
        $ip = $request->ip() ?? 'unknown';
        $key = sprintf('eventpulse:rl:ip:%s', $ip);

        if ($this->limiter->tooManyAttempts($key, self::LIMIT)) {
            $retryAfter = $this->limiter->availableIn($key);

            return new JsonResponse([
                'error' => [
                    'code' => 'RATE_LIMITED',
                    'message' => 'You have exceeded the rate limit for this endpoint.',
                    'details' => ['retry_after' => $retryAfter],
                ],
            ], Response::HTTP_TOO_MANY_REQUESTS, [
                'Retry-After' => (string) $retryAfter,
                'X-RateLimit-Limit' => (string) self::LIMIT,
                'X-RateLimit-Remaining' => '0',
            ]);
        }

        $this->limiter->hit($key, self::WINDOW_SECONDS);

        /** @var Response $response */
        $response = $next($request);

        $remaining = max(0, self::LIMIT - $this->limiter->attempts($key));
        $response->headers->set('X-RateLimit-Limit', (string) self::LIMIT);
        $response->headers->set('X-RateLimit-Remaining', (string) $remaining);

        return $response;
    }
}
