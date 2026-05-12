<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Dlq\GetDlqController;
use App\Http\Controllers\Api\V1\Dlq\ListDlqController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\SubmitNotificationController;
use App\Http\Controllers\Api\V1\WebhookDestination\DisableWebhookDestinationController;
use App\Http\Controllers\Api\V1\WebhookDestination\ListWebhookDestinationsController;
use App\Http\Controllers\Api\V1\WebhookDestination\RegisterWebhookDestinationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| EventPulse API routes
|--------------------------------------------------------------------------
|
| Versioned under `/api/v1/`. Endpoints implemented to date:
|
|   POST   /notifications              — Day 3:  submit a notification
|   GET    /dlq                        — Day 8:  list dead-lettered notifications
|   GET    /dlq/{id}                   — Day 8:  inspect one dead-lettered notification
|   POST   /webhook-destinations       — Day 9:  register a webhook destination
|   GET    /webhook-destinations       — Day 9:  list webhook destinations
|   DELETE /webhook-destinations/{id}  — Day 9:  disable a webhook destination
|   GET    /health                     — Day 10: liveness probe (no auth)
|   GET    /health/detailed            — Day 10: readiness probe (no auth, IP-throttled)
|
| Middleware:
|  - `auth.api-key`              — resolves Bearer token → ApiKey model.
|  - `throttle.api`              — per-API-key rate limiting (write: 100/min; read: 600/min).
|  - `scope:notifications:write` — POST /notifications.
|  - `scope:dlq:read`            — DLQ inspection endpoints.
|  - `scope:notifications:write` — webhook destination write operations.
|  - `scope:notifications:read`  — webhook destination list.
|  - `throttle.ip`               — IP-based rate limiting for unauthenticated endpoints.
|
| Scope choice for webhook destinations:
|  The spec (openapi.yaml) assigns `notifications:write` to POST and DELETE
|  on /webhook-destinations, and `notifications:read` to GET. This mirrors
|  the notification resource scopes: managing destinations is part of managing
|  the notification delivery capability for an API key.
|
| Rate limiting strategy:
|  Authenticated endpoints use `throttle.api` (ThrottleApiRequests), which
|  resolves the limit from the ApiKey model and maintains separate write/read
|  buckets in Redis. Health endpoints use `throttle.ip` (ThrottleIpRequests)
|  at 60/min per IP; they intentionally require no credentials so
|  orchestrators and monitoring can call them freely.
*/

// ── Health probes (unauthenticated) ─────────────────────────────────────────
Route::prefix('v1')->group(function (): void {
    Route::get('health', [HealthController::class, 'liveness'])
        ->name('api.v1.health.liveness');

    Route::get('health/detailed', [HealthController::class, 'readiness'])
        ->middleware('throttle.ip')
        ->name('api.v1.health.readiness');
});

// ── Authenticated, rate-limited routes ──────────────────────────────────────
Route::prefix('v1')
    ->middleware(['api', 'auth.api-key', 'throttle.api'])
    ->group(function (): void {

        // ── Notifications ────────────────────────────────────────────────
        Route::post('notifications', SubmitNotificationController::class)
            ->middleware('scope:notifications:write')
            ->name('api.v1.notifications.create');

        // ── Dead-letter queue (inspection only — Day 8) ──────────────────
        Route::get('dlq', ListDlqController::class)
            ->middleware('scope:dlq:read')
            ->name('api.v1.dlq.list');

        Route::get('dlq/{id}', GetDlqController::class)
            ->middleware('scope:dlq:read')
            ->name('api.v1.dlq.get');

        // ── Webhook destinations (Day 9) ─────────────────────────────────
        Route::post('webhook-destinations', RegisterWebhookDestinationController::class)
            ->middleware('scope:notifications:write')
            ->name('api.v1.webhook-destinations.create');

        Route::get('webhook-destinations', ListWebhookDestinationsController::class)
            ->middleware('scope:notifications:read')
            ->name('api.v1.webhook-destinations.list');

        Route::delete('webhook-destinations/{id}', DisableWebhookDestinationController::class)
            ->middleware('scope:notifications:write')
            ->name('api.v1.webhook-destinations.disable');
    });
