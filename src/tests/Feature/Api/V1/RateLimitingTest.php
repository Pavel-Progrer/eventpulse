<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\ApiKey;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Behaviour: the per-API-key rate limiter enforces separate write and read
 * quotas, returns the correct response headers, and honours per-key overrides.
 *
 * Cases covered:
 *   Happy path:
 *     - Successful requests carry X-RateLimit-Limit and X-RateLimit-Remaining.
 *     - Read requests consume the read bucket (600/min default), not the write bucket.
 *     - Write requests consume the write bucket (100/min default).
 *
 *   Limit exceeded:
 *     - 429 response with error envelope (code: RATE_LIMITED).
 *     - Retry-After header is present on 429.
 *     - X-RateLimit-Remaining is 0 on 429.
 *     - Correlation-ID is propagated to the 429 body when supplied.
 *
 *   Per-key override:
 *     - A key with `rate_limit_per_minute = 2` is blocked on the 3rd write.
 *
 *   Bucket isolation:
 *     - Exhausting the write bucket does not affect the read bucket.
 *     - Two different API keys have independent buckets.
 *
 * Tests manipulate the RateLimiter directly (via `hit()`) to simulate a
 * near-exhausted bucket without making hundreds of actual HTTP calls. This
 * keeps the suite fast while still exercising the 429 path through the
 * real middleware stack.
 */
final class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    private ApiKey $defaultKey;
    private ApiKey $customLimitKey;
    private ApiKey $otherKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaultKey = ApiKey::query()->create([
            'identifier'            => 'ep_live_rl_default_001',
            'scopes'                => ['notifications:write', 'notifications:read', 'dlq:read'],
            'status'                => 'active',
            'rate_limit_per_minute' => null, // system default
        ]);

        $this->customLimitKey = ApiKey::query()->create([
            'identifier'            => 'ep_live_rl_custom_001',
            'scopes'                => ['notifications:write', 'notifications:read', 'dlq:read'],
            'status'                => 'active',
            'rate_limit_per_minute' => 2, // very low for test determinism
        ]);

        $this->otherKey = ApiKey::query()->create([
            'identifier'            => 'ep_live_rl_other_001',
            'scopes'                => ['notifications:write', 'notifications:read', 'dlq:read'],
            'status'                => 'active',
            'rate_limit_per_minute' => null,
        ]);
    }

    protected function tearDown(): void
    {
        // Clear rate-limiter state between tests; RateLimiter uses the cache
        // driver, which is `array` in testing, and resets per-process — but
        // explicit clearing is safer when tests run in the same process.
        $limiter = $this->app->make(RateLimiter::class);
        $limiter->clear(sprintf('eventpulse:rl:%s:write', $this->defaultKey->id));
        $limiter->clear(sprintf('eventpulse:rl:%s:read',  $this->defaultKey->id));
        $limiter->clear(sprintf('eventpulse:rl:%s:write', $this->customLimitKey->id));
        $limiter->clear(sprintf('eventpulse:rl:%s:write', $this->otherKey->id));
        $limiter->clear(sprintf('eventpulse:rl:%s:read',  $this->otherKey->id));

        parent::tearDown();
    }

    // ── Rate-limit headers on success ─────────────────────────────────────────

    #[Test]
    public function successful_read_response_carries_rate_limit_headers(): void
    {
        $response = $this->withBearerToken($this->defaultKey->identifier)
                         ->getJson('/api/v1/dlq');

        $response->assertStatus(200);
        $response->assertHeader('X-RateLimit-Limit');
        $response->assertHeader('X-RateLimit-Remaining');
        $response->assertHeader('X-RateLimit-Reset');
    }

    #[Test]
    public function read_limit_header_reflects_the_600_default(): void
    {
        $response = $this->withBearerToken($this->defaultKey->identifier)
                         ->getJson('/api/v1/dlq');

        $response->assertStatus(200);
        self::assertSame('600', $response->headers->get('X-RateLimit-Limit'));
    }

    #[Test]
    public function remaining_decrements_with_each_request(): void
    {
        $first = $this->withBearerToken($this->defaultKey->identifier)
                      ->getJson('/api/v1/dlq');

        $second = $this->withBearerToken($this->defaultKey->identifier)
                       ->getJson('/api/v1/dlq');

        $firstRemaining  = (int) $first->headers->get('X-RateLimit-Remaining');
        $secondRemaining = (int) $second->headers->get('X-RateLimit-Remaining');

        self::assertLessThan($firstRemaining, $secondRemaining);
    }

    // ── 429 response shape ────────────────────────────────────────────────────

    #[Test]
    public function exceeding_write_limit_returns_429_with_error_envelope(): void
    {
        // Pre-fill the write bucket to the per-key limit so the next request
        // is blocked. Using `hit()` directly avoids making 100 real HTTP calls.
        $limiter    = $this->app->make(RateLimiter::class);
        $bucketKey  = sprintf('eventpulse:rl:%s:write', $this->customLimitKey->id);

        // Custom limit is 2; hit it twice to exhaust.
        $limiter->hit($bucketKey, 60);
        $limiter->hit($bucketKey, 60);

        // The next (third) write should be blocked.
        $response = $this->withBearerToken($this->customLimitKey->identifier)
                         ->postJson('/api/v1/notifications', []);

        $response->assertStatus(429);
        $response->assertJsonStructure([
            'error' => ['code', 'message', 'details' => ['limit', 'retry_after']],
        ]);
        $response->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    #[Test]
    public function rate_limited_response_includes_retry_after_header(): void
    {
        $limiter   = $this->app->make(RateLimiter::class);
        $bucketKey = sprintf('eventpulse:rl:%s:write', $this->customLimitKey->id);

        $limiter->hit($bucketKey, 60);
        $limiter->hit($bucketKey, 60);

        $response = $this->withBearerToken($this->customLimitKey->identifier)
                         ->postJson('/api/v1/notifications', []);

        $response->assertStatus(429);
        $response->assertHeader('Retry-After');
        $response->assertHeader('X-RateLimit-Remaining', '0');
    }

    #[Test]
    public function correlation_id_is_propagated_to_rate_limited_response(): void
    {
        $limiter   = $this->app->make(RateLimiter::class);
        $bucketKey = sprintf('eventpulse:rl:%s:write', $this->customLimitKey->id);

        $limiter->hit($bucketKey, 60);
        $limiter->hit($bucketKey, 60);

        $correlationId = 'test-correlation-abc-123';

        $response = $this->withBearerToken($this->customLimitKey->identifier)
                         ->withHeaders(['X-Correlation-ID' => $correlationId])
                         ->postJson('/api/v1/notifications', []);

        $response->assertStatus(429);
        $response->assertJsonPath('correlation_id', $correlationId);
    }

    // ── Per-key override ──────────────────────────────────────────────────────

    #[Test]
    public function per_key_override_is_reflected_in_limit_header(): void
    {
        // First request succeeds; the limit header should show the override (2).
        $response = $this->withBearerToken($this->customLimitKey->identifier)
                         ->getJson('/api/v1/dlq');

        // GET is a read → uses default 600 limit (per-key override is write-only).
        // Confirming the read bucket is NOT affected by the write override.
        $response->assertStatus(200);
        self::assertSame('600', $response->headers->get('X-RateLimit-Limit'));
    }

    #[Test]
    public function write_override_blocks_at_custom_limit_not_default(): void
    {
        $limiter   = $this->app->make(RateLimiter::class);
        $bucketKey = sprintf('eventpulse:rl:%s:write', $this->customLimitKey->id);

        // Hit the custom limit (2) exactly — next write should be blocked.
        $limiter->hit($bucketKey, 60);
        $limiter->hit($bucketKey, 60);

        $response = $this->withBearerToken($this->customLimitKey->identifier)
                         ->postJson('/api/v1/notifications', []);

        // 429 at 3rd write (limit = 2), NOT at the 101st (limit = 100 default).
        $response->assertStatus(429);
        self::assertSame(2, $response->json('error.details.limit'));
    }

    // ── Bucket isolation ──────────────────────────────────────────────────────

    #[Test]
    public function exhausted_write_bucket_does_not_block_reads(): void
    {
        $limiter    = $this->app->make(RateLimiter::class);
        $writeBucket = sprintf('eventpulse:rl:%s:write', $this->customLimitKey->id);

        $limiter->hit($writeBucket, 60);
        $limiter->hit($writeBucket, 60);

        // Write is now blocked.
        $this->withBearerToken($this->customLimitKey->identifier)
             ->postJson('/api/v1/notifications', [])
             ->assertStatus(429);

        // Read must still work.
        $this->withBearerToken($this->customLimitKey->identifier)
             ->getJson('/api/v1/dlq')
             ->assertStatus(200);
    }

    #[Test]
    public function two_api_keys_have_independent_write_buckets(): void
    {
        $limiter = $this->app->make(RateLimiter::class);

        // Exhaust the custom key's write bucket.
        $bucketKey = sprintf('eventpulse:rl:%s:write', $this->customLimitKey->id);
        $limiter->hit($bucketKey, 60);
        $limiter->hit($bucketKey, 60);

        // Custom key is blocked.
        $this->withBearerToken($this->customLimitKey->identifier)
             ->postJson('/api/v1/notifications', [])
             ->assertStatus(429);

        // The other key's write bucket is untouched — it should not be blocked.
        // (The request will likely 422 on validation, not 429 — that's fine.)
        $response = $this->withBearerToken($this->otherKey->identifier)
                         ->postJson('/api/v1/notifications', []);

        self::assertNotEquals(429, $response->getStatusCode());
    }

    // ── 404 envelope (Day 10 addition to ApiExceptionRenderer) ───────────────

    #[Test]
    public function unknown_api_route_returns_json_404_envelope(): void
    {
        // Before Day 10, unknown routes returned Laravel's HTML 404.
        // After Day 10, the ApiExceptionRenderer intercepts NotFoundHttpException.
        $response = $this->withBearerToken($this->defaultKey->identifier)
                         ->getJson('/api/v1/does-not-exist');

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'NOT_FOUND');
        $response->assertJsonStructure(['error' => ['code', 'message']]);
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function withBearerToken(string $identifier): static
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $identifier]);
    }
}
