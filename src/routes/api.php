<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Dlq\DiscardDlqController;
use App\Http\Controllers\Api\V1\Dlq\GetDlqController;
use App\Http\Controllers\Api\V1\Dlq\ListDlqController;
use App\Http\Controllers\Api\V1\Dlq\ReplayDlqController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Notification\GetNotificationController;
use App\Http\Controllers\Api\V1\Notification\ListNotificationsController;
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
|   GET    /notifications              — Day 11: list notifications (paginated)
|   GET    /notifications/{id}         — Day 11: inspect a single notification
|   GET    /dlq                        — Day 8:  list dead-lettered notifications
|   GET    /dlq/{id}                   — Day 8:  inspect one dead-lettered notification
|   POST   /dlq/{id}/replay            — Day 11: replay a dead-lettered notification
|   DELETE /dlq/{id}                   — Day 11: discard a dead-lettered notification
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
|  - `scope:notifications:read`  — GET /notifications, GET /notifications/{id}.
|  - `scope:dlq:read`            — DLQ inspection endpoints.
|  - `scope:dlq:replay`          — DLQ replay and discard endpoints.
|  - `scope:notifications:write` — webhook destination write operations.
|  - `scope:notifications:read`  — webhook destination list.
|  - `throttle.ip`               — IP-based rate limiting for unauthenticated endpoints.
|
| Route ordering note (notifications vs dlq):
|  `GET /notifications` and `GET /notifications/{id}` are registered in order:
|  the parameterised route comes after the plain list route so Laravel's router
|  never mistakes "notifications" (with no segment after the slash) for an id
|  parameter. The router matches in registration order.
|
|  The DLQ replay route (`POST /dlq/{id}/replay`) must be registered BEFORE
|  `GET /dlq/{id}` would theoretically conflict — they don't conflict here
|  because they differ by HTTP method, but explicit ordering keeps the group
|  readable: action routes above resource routes.
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

        Route::get('notifications', ListNotificationsController::class)
            ->middleware('scope:notifications:read')
            ->name('api.v1.notifications.list');

        Route::get('notifications/{id}', GetNotificationController::class)
            ->middleware('scope:notifications:read')
            ->name('api.v1.notifications.get');

        // ── Dead-letter queue ────────────────────────────────────────────
        Route::get('dlq', ListDlqController::class)
            ->middleware('scope:dlq:read')
            ->name('api.v1.dlq.list');

        Route::get('dlq/{id}', GetDlqController::class)
            ->middleware('scope:dlq:read')
            ->name('api.v1.dlq.get');

        // POST and DELETE share the same `dlq:replay` scope gate.
        Route::post('dlq/{id}/replay', ReplayDlqController::class)
            ->middleware('scope:dlq:replay')
            ->name('api.v1.dlq.replay');

        Route::delete('dlq/{id}', DiscardDlqController::class)
            ->middleware('scope:dlq:replay')
            ->name('api.v1.dlq.discard');

        // ── Webhook destinations ─────────────────────────────────────────
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
