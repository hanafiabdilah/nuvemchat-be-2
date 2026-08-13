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
        // channels: is deliberately NOT declared here — it would register
        // /broadcasting/auth on the `web` middleware group, which authenticates
        // from the session. The SPA holds a Sanctum Bearer token and sends no
        // cookies, so every private-channel subscription would 403. See the
        // explicit withBroadcasting() below.
        health: '/up',
        then: function (): void {
            // Widget routes are called cross-origin from third-party sites.
            // No sessions, no cookies, no CSRF — just thin HTTP + CORS (handled
            // globally via config/cors.php).
            \Illuminate\Support\Facades\Route::middleware(\Illuminate\Routing\Middleware\SubstituteBindings::class)
                ->group(__DIR__.'/../routes/widget.php');
        },
    )
    // Channel authorization runs on the API stack with the Sanctum guard, so
    // Echo can authorize `private-*` subscriptions with the same Bearer token
    // it already sends to /api. Endpoint: POST /api/broadcasting/auth.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']],
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
            'messaging.window' => \App\Http\Middleware\EnsureMessagingWindowIsOpen::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Meta's refusals are worth passing through verbatim — "The submitted
        // image is not a valid JPEG" tells the user exactly what to fix, and
        // nothing we could write in its place would be as useful. The `code`
        // separates the one failure the UI can offer a remedy for (the account
        // was connected before publishing existed and needs re-authorizing)
        // from the ones it can only report.
        $exceptions->render(function (\App\Exceptions\InstagramApiException $e, \Illuminate\Http\Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->isPermissionError() ? 'instagram_permission_required' : 'instagram_error',
            ], $e->httpStatus());
        });
    })->create();
