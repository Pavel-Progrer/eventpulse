<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Behaviour: the liveness and readiness health probes return the correct
 * status codes and response shapes under normal and degraded conditions.
 *
 * Cases covered:
 *   GET /api/v1/health (liveness):
 *     - Always 200 regardless of dependencies.
 *     - No authentication required.
 *     - Returns `{"status": "ok"}`.
 *
 *   GET /api/v1/health/detailed (readiness):
 *     - 200 with all checks ok when dependencies are healthy.
 *     - 503 when the database is unavailable.
 *     - 503 when Redis/cache is unavailable.
 *     - Response shape includes `status`, `checks`, and `version`.
 *     - Each check includes `status` and — on success — `latency_ms`.
 *     - No authentication required (IP-rate-limited, not auth-gated).
 *     - Rate limiting headers are present on success responses.
 *
 * Degraded-dependency tests avoid mocking Laravel's database internals.
 * The DB failure test uses `DB::purge()` + an unreachable host so the real
 * connection failure path fires naturally through the controller's try/catch.
 * The Redis failure test uses Mockery against the Cache Repository contract,
 * which the container resolves directly (unlike the DB connection, which goes
 * through the DatabaseManager's own connection pool).
 */
final class HealthTest extends TestCase
{
    use RefreshDatabase;

    // ── Liveness ──────────────────────────────────────────────────────────────

    #[Test]
    public function liveness_returns_200_without_authentication(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200)
            ->assertExactJson(['status' => 'ok']);
    }

    #[Test]
    public function liveness_returns_200_with_no_authorization_header(): void
    {
        // Explicit: liveness must never require auth, even if the middleware
        // stack is misconfigured. This assertion makes regression obvious.
        $response = $this->withHeaders([])->getJson('/api/v1/health');

        $response->assertStatus(200);
    }

    // ── Readiness — happy path ────────────────────────────────────────────────

    #[Test]
    public function readiness_returns_200_when_all_checks_pass(): void
    {
        $response = $this->getJson('/api/v1/health/detailed');

        $response->assertStatus(200);

        $response->assertJsonStructure([
            'status',
            'checks' => [
                'database' => ['status', 'latency_ms'],
                'redis' => ['status', 'latency_ms'],
                'queue_depth' => ['status', 'pending'],
            ],
            'version',
        ]);

        $response->assertJsonPath('status', 'ok');
        $response->assertJsonPath('checks.database.status', 'ok');
        $response->assertJsonPath('checks.redis.status', 'ok');
        $response->assertJsonPath('checks.queue_depth.status', 'ok');
    }

    #[Test]
    public function readiness_database_latency_is_a_non_negative_integer(): void
    {
        $response = $this->getJson('/api/v1/health/detailed');
        $response->assertStatus(200);

        $latency = $response->json('checks.database.latency_ms');

        self::assertIsInt($latency);
        self::assertGreaterThanOrEqual(0, $latency);
    }

    #[Test]
    public function readiness_queue_depth_is_a_non_negative_integer(): void
    {
        $response = $this->getJson('/api/v1/health/detailed');
        $response->assertStatus(200);

        $pending = $response->json('checks.queue_depth.pending');

        self::assertIsInt($pending);
        self::assertGreaterThanOrEqual(0, $pending);
    }

    #[Test]
    public function readiness_version_is_a_string(): void
    {
        $response = $this->getJson('/api/v1/health/detailed');
        $response->assertStatus(200);

        self::assertIsString($response->json('version'));
    }

    // ── Readiness — degraded database ────────────────────────────────────────

    #[Test]
    public function readiness_returns_503_when_database_is_unavailable(): void
    {
        // Point the connection at an unreachable host and purge the pool so
        // the next query opens a fresh (failing) connection. This exercises
        // the real failure path through the controller's try/catch without
        // fighting Laravel's DatabaseManager, which maintains its own
        // connection pool that bypasses plain container bindings.
        //
        // The original host is restored in the finally block so that
        // RefreshDatabase's post-test teardown (transaction rollback) can
        // reconnect successfully. Without this, the teardown crashes with
        // the same PDOException the test was trying to provoke.
        $originalHost = config('database.connections.pgsql.host');

        try {
            config(['database.connections.pgsql.host' => '0.0.0.1']);
            DB::purge('pgsql');

            $response = $this->getJson('/api/v1/health/detailed');

            $response->assertStatus(503);
            $response->assertJsonPath('status', 'degraded');
            $response->assertJsonPath('checks.database.status', 'fail');
            self::assertNotEmpty($response->json('checks.database.error'));
        } finally {
            config(['database.connections.pgsql.host' => $originalHost]);
            DB::purge('pgsql');
        }
    }

    // ── Readiness — degraded Redis ────────────────────────────────────────────

    #[Test]
    public function readiness_returns_503_when_redis_is_unavailable(): void
    {
        // Use Mockery to create a full implementation of the Repository
        // contract that throws on put(). An anonymous class is not usable
        // here because Repository extends Psr\SimpleCache\CacheInterface,
        // and the combined interface surface (sear, getStore, PSR set/delete/
        // clear/getMultiple/setMultiple/deleteMultiple/has) varies across
        // Laravel versions. Mockery::mock() generates all stubs automatically
        // and lets us override only the one behaviour we need.
        $mock = \Mockery::mock(Repository::class);
        $mock->allows()->put(\Mockery::any(), \Mockery::any(), \Mockery::any())
            ->andThrow(new \RuntimeException('Connection to Redis refused'));

        $this->app->instance(Repository::class, $mock);

        $response = $this->getJson('/api/v1/health/detailed');

        $response->assertStatus(503);
        $response->assertJsonPath('status', 'degraded');
        $response->assertJsonPath('checks.redis.status', 'fail');
        self::assertNotEmpty($response->json('checks.redis.error'));
    }

    // ── Readiness — rate-limit headers ───────────────────────────────────────

    #[Test]
    public function readiness_includes_rate_limit_headers(): void
    {
        $response = $this->getJson('/api/v1/health/detailed');

        // The `throttle.ip` middleware attaches X-RateLimit-* to successful
        // responses. These are present only on the detailed endpoint (not
        // liveness, which bypasses the middleware).
        $response->assertHeader('X-RateLimit-Limit');
        $response->assertHeader('X-RateLimit-Remaining');
    }

    // ── Readiness — unauthenticated ──────────────────────────────────────────

    #[Test]
    public function readiness_requires_no_authorization(): void
    {
        // Should not 401 even without a Bearer token.
        $response = $this->withHeaders([])->getJson('/api/v1/health/detailed');

        self::assertNotEquals(401, $response->getStatusCode());
    }
}
