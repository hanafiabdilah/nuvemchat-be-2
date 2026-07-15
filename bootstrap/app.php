<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function (): void {
            // Widget routes are called cross-origin from third-party sites.
            // No sessions, no cookies, no CSRF — just thin HTTP + CORS (handled
            // globally via config/cors.php).
            \Illuminate\Support\Facades\Route::middleware(\Illuminate\Routing\Middleware\SubstituteBindings::class)
                ->group(__DIR__.'/../routes/widget.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->validateCsrfTokens([
            '/webhook/*',
            '/oauth/instagram/deauthorize',
            '/oauth/instagram/data-deletion',
            '/oauth/facebook/deauthorize',
            '/oauth/facebook/data-deletion',
        ]);

        // Register Spatie Permission middleware aliases
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'super-admin' => \App\Http\Middleware\EnsureUserIsSuperAdmin::class,
            'subscription.active' => \App\Http\Middleware\EnsureSubscriptionActive::class,
            'feature' => \App\Http\Middleware\EnsureFeatureEnabled::class,
            'whatsapp.verified' => \App\Http\Middleware\EnsureWhatsAppVerified::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
