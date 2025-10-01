<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

/**
 * Laravel Bootstrap (Application Factory)
 *
 * Purpose:
 * - Configure the application’s base path, route files, middleware stack and exception handling.
 * - Produce the fully initialised Application instance.
 *
 * Notes:
 * - Routing uses explicit file paths for web/api/console routes.
 * - Health endpoint (/up) is enabled for uptime checks.
 * - Middleware and exception hooks are left as extension points.
 */

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',       // Browser-facing (sessionful) routes
        api: __DIR__ . '/../routes/api.php',       // Stateless API routes (prefixed with /api)
        commands: __DIR__ . '/../routes/console.php', // Artisan console commands
        health: '/up',                              // Lightweight health-check endpoint
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Register global middleware or middleware groups here if/when needed.
        // Example: $middleware->append(\App\Http\Middleware\TrustProxies::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Centralise exception rendering/reporting customisation here.
        // Example: $exceptions->renderable(fn(\Throwable $e) => ...);
    })
    ->create();
