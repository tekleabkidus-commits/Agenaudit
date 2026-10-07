<?php

use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\PersistentDeviceLogin;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            PersistentDeviceLogin::class,
            EnsureActiveUser::class,
            SecurityHeaders::class,
        ]);

        $middleware->alias([
            'role' => EnsureRole::class,
        ]);

        $middleware->redirectGuestsTo('/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Domain exceptions use Laravel's normal reporting/rendering pipeline.
    })
    ->create();
