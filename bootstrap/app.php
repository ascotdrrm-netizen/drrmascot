<?php

use App\Http\Middleware\EnsureUserRole;
use App\Http\Middleware\NormalizeEmails;
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
        $middleware->alias([
            'role' => EnsureUserRole::class,
            'staff' => \App\Http\Middleware\EnsureStaffRole::class,
        ]);
        // E-mail addresses are compared lower-case on every database; AJAX
        // requests get JSON instead of a redirect (resources/js/modules/ajax.js).
        $middleware->web(append: [NormalizeEmails::class, \App\Http\Middleware\PrivateResponses::class, \App\Http\Middleware\AjaxResponses::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
