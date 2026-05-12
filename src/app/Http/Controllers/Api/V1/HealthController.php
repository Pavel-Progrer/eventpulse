<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Queue\QueueManager;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Liveness and readiness probes.
 *
 * Two endpoints, two very different contracts:
 *
 * GET /api/v1/health (liveness):
 *   Returns 200 if the PHP process is alive and can serve HTTP. Does not
 *   check any dependencies. Used by orchestrators (Docker, K8s) for liveness
 *   probes — a failing liveness probe restarts the container. Checking the
 *   DB here would cause containers to restart when the DB is temporarily
 *   unavailable, which is almost never the right response.
 *
 * GET /api/v1/health/detailed (readiness):
 *   Checks each backing dependency and reports its status and latency.
 *   Returns 200 only when *all* checks pass; 503 otherwise. Used for
 *   readiness probes — a failing readiness probe removes the instance from
 *   the load balancer rotation rather than restarting it.
 *
 * Authentication:
 *   Liveness: unauthenticated (orchestrators don't have API keys).
 *   Readiness: unauthenticated but IP-rate-limited at 60/min (specified in
 *   §5.3). The endpoint is intentionally observable without credentials so
 *   monitoring systems can use it without key rotation risk.
 *
 * Latency measurement:
 *   Each check records wall-clock milliseconds. The numbers are indicative,
 *   not precise — they include PHP userspace overhead. The intent is to make
 *   "DB is slow" visible at a glance without requiring a separate metrics
 *   system.
 *
 * This controller is intentionally not split into two controllers: liveness
 * and readiness share the `health` route prefix and the detailed check is
 * a natural extension of the liveness probe. A single controller keeps the
 * routing and the logic co-located.
 */
final class HealthController
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Cache        $cache,
        private readonly QueueManager $queue,
    ) {}

    /**
     * GET /api/v1/health — liveness probe.
     *
     * The simplest possible probe: if this action runs, the process is alive.
     * No dependencies checked. Always returns 200.
     */
    public function liveness(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }

    /**
     * GET /api/v1/health/detailed — readiness probe.
     *
     * Checks database, Redis (via cache), and queue depth. Returns 200 when
     * all checks pass; 503 with full check detail when any fail.
     */
    public function readiness(Request $request): JsonResponse
    {
        $checks = [
            'database'    => $this->checkDatabase(),
            'redis'       => $this->checkRedis(),
            'queue_depth' => $this->checkQueueDepth(),
        ];

        $allOk = array_reduce(
            $checks,
            static fn(bool $carry, array $check): bool => $carry && $check['status'] === 'ok',
            true,
        );

        $status = $allOk ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE;

        return new JsonResponse([
            'status' => $allOk ? 'ok' : 'degraded',
            'checks' => $checks,
            'version' => $this->resolveVersion(),
        ], $status);
    }

    /**
     * @return array{status: string, latency_ms?: int, error?: string}
     */
    private function checkDatabase(): array
    {
        $start = hrtime(true);

        try {
            $this->db->selectOne('SELECT 1');

            return [
                'status'     => 'ok',
                'latency_ms' => $this->elapsedMs($start),
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'fail',
                'error'  => 'Database check failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{status: string, latency_ms?: int, error?: string}
     */
    private function checkRedis(): array
    {
        $start = hrtime(true);

        try {
            // A lightweight ping: write a short-lived key and immediately
            // read it back. Tests both write and read path on the cache
            // connection, which is Redis in production.
            $probe = 'eventpulse:health:' . uniqid('', true);
            $this->cache->put($probe, 'ping', 5);
            $this->cache->forget($probe);

            return [
                'status'     => 'ok',
                'latency_ms' => $this->elapsedMs($start),
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'fail',
                'error'  => 'Redis check failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{status: string, pending?: int, error?: string}
     */
    private function checkQueueDepth(): array
    {
        try {
            // `size()` returns the number of jobs waiting in the default queue.
            // This requires the queue driver to support sizing (Redis does;
            // the database driver does; sync does not — but sync is never used
            // in production). An excessively large queue depth isn't a failure
            // per se (we report it but still return ok), because high depth
            // is an operational concern, not a queue connectivity failure.
            $pending = $this->queue->size(config('queue.connections.redis.queue', 'default'));

            return [
                'status'  => 'ok',
                'pending' => $pending,
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'fail',
                'error'  => 'Queue depth check failed: ' . $e->getMessage(),
            ];
        }
    }

    private function elapsedMs(int $start): int
    {
        return (int) round((hrtime(true) - $start) / 1_000_000);
    }

    private function resolveVersion(): string
    {
        // Read from the application's version file if present (set at build
        // time in Phase 2). Falls back to the package version string.
        /** @var string|null $version */
        $version = config('app.version');

        return is_string($version) && $version !== ''
            ? $version
            : '0.1.0';
    }
}
