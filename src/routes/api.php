<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Dlq\GetDlqController;
use App\Http\Controllers\Api\V1\Dlq\ListDlqController;
use App\Http\Controllers\Api\V1\SubmitNotificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| EventPulse API routes
|--------------------------------------------------------------------------
|
| Versioned under `/api/v1/`. Endpoints implemented to date:
|   POST /notifications      — Day 3, submit a notification
|   GET  /dlq                — Day 8, list dead-lettered notifications
|   GET  /dlq/{id}           — Day 8, inspect one dead-lettered notification
|
| Coming in later days: notification status (GET /notifications/{id}),
| webhook destinations CRUD, DLQ replay & discard.
|
| Middleware:
|  - `auth.api-key` — resolves the Bearer token to an `ApiKey` model and
|    attaches it to the request. (HMAC verification arrives in Day 9.)
|  - `scope:notifications:write` — required for POST /notifications.
|  - `scope:dlq:read`            — required for the DLQ inspection endpoints.
|
| Per ADR-0006 §"DLQ visibility is tenant-scoped", the DLQ endpoints
| also enforce a per-API-key data-layer filter — the middleware scope
| controls "may this caller use the endpoint at all"; the data filter
| controls "which rows are even visible." Two distinct concerns,
| layered.
*/

Route::prefix('v1')
    ->middleware(['api', 'auth.api-key'])
    ->group(function (): void {
        // ── Notifications ───────────────────────────────────────────────
        Route::post('notifications', SubmitNotificationController::class)
            ->middleware('scope:notifications:write')
            ->name('api.v1.notifications.create');

        // ── Dead-letter queue (inspection only — Day 8) ────────────────
        Route::get('dlq', ListDlqController::class)
            ->middleware('scope:dlq:read')
            ->name('api.v1.dlq.list');

        Route::get('dlq/{id}', GetDlqController::class)
            ->middleware('scope:dlq:read')
            ->name('api.v1.dlq.get');
    });
