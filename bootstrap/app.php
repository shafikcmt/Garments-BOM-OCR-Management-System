<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

use App\Http\Middleware\RoleCheck;


return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function ($middleware): void {

        // -------------------------
        // Global Middleware (if any)
        // -------------------------
        // $middleware->push(App\Http\Middleware\SomeGlobalMiddleware::class);

        // -------------------------
        // Alias Middleware
        // -------------------------
       $middleware->alias([
            'role' => RoleCheck::class,
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();
