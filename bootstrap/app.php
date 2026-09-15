<?php

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
        // This app has no 'login' route (no web auth, API-only) - but
        // Laravel's ApplicationBuilder unconditionally defaults every app to
        // redirectGuestsTo(fn () => route('login')) before this callback
        // runs. Without overriding it, any unauthenticated request that
        // doesn't explicitly ask for JSON (e.g. opened directly in a
        // browser, or missing an Accept header) crashes with
        // RouteNotFoundException instead of a clean 401 - the guest
        // middleware tries to build a redirect to a route that was never
        // defined. Returning null here means "never redirect," so an
        // unauthenticated request always falls through to a normal
        // AuthenticationException, which the exceptions config below
        // already renders as JSON for API requests.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
