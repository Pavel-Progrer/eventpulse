<?php

use App\Exceptions\ApiExceptionRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // For /api/* requests, delegate to the renderer that produces the
        // standardised JSON envelope (per ADR-0003 §3 and the OpenAPI
        // ValidationErrorResponse schema). Returning null means "I don't
        // handle this — let Laravel render its default." Anything non-null
        // short-circuits the rest of the pipeline.
        $exceptions->render(function (Throwable $e, Request $request): mixed {
            if (! $request->is('api/*')) {
                return null;
            }

            return (new ApiExceptionRenderer)->render($request, $e);
        });
    })->create();
