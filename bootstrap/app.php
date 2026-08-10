<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

use App\Http\Middleware\RoleCheck;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;


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
            // 'role' stays on the project's own RoleCheck. Spatie ships a
            // RoleMiddleware that would answer to the same name, and every one
            // of the existing route guards is written against this one — so it
            // is deliberately NOT re-pointed.
            'role' => RoleCheck::class,

            // Spatie's permission middleware, registered under names of their
            // own so they sit beside 'role' instead of competing with it.
            //
            //   perm:store.issues.view          — must hold the permission
            //   role_or_perm:store|store.view   — either will do
            //
            // role_or_perm is the one that makes the move off role-only guards
            // safe: a group can accept the new permission while still accepting
            // the role it accepts today, so nobody loses access on the way.
            // Nothing uses these yet; enforcement is a later phase.
            'perm' => PermissionMiddleware::class,
            'role_or_perm' => RoleOrPermissionMiddleware::class,
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();
