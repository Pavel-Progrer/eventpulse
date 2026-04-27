<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\SubmitNotificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| EventPulse API routes
|--------------------------------------------------------------------------
|
| Versioned under `/api/v1/`. Day 3 wires up only the create endpoint;
| later days add status, listing, DLQ, webhook destinations, search.
|
| Middleware:
|  - `auth.api-key` — resolves the Bearer token to an `ApiKey` model and
|    attaches it to the request. (HMAC verification arrives in Day 9.)
|  - `scope:notifications:write` — checks the resolved key carries the
|    scope this endpoint requires.
|
*/

Route::prefix('v1')
    ->middleware(['api', 'auth.api-key'])
    ->group(function (): void {
        Route::post('notifications', SubmitNotificationController::class)
            ->middleware('scope:notifications:write')
            ->name('api.v1.notifications.create');
    });
