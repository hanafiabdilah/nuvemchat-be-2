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
            \Illuminate\Support\Facades\Route::middleware([
                \Illuminate\Routing\Middleware\SubstituteBindings::class,
                \App\Http\Middleware\SanitizeUpstreamErrors::class,
            ])->group(__DIR__.'/../routes/widget.php');
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

        // Safety net for upstream wording that escapes an un-translated catch
        // block. The call sites do the real work (App\Support\Errors\
        // UpstreamError); this is what keeps the guarantee true after the next
        // integration is added. Exempts api/admin/* — see the middleware.
        $middleware->api(append: [
            \App\Http\Middleware\SanitizeUpstreamErrors::class,
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
        // Meta's refusals used to be passed through verbatim, on the grounds
        // that "The submitted image is not a valid JPEG" says exactly what to
        // fix. Most of them do not: the same field also carries OAuth codes,
        // fbtrace ids and internal object names, all of it written for whoever
        // integrates with Graph rather than for the person who just picked a
        // photo. So it is translated like every other upstream — the log line
        // and the `ref` keep the original.
        //
        // The permission case keeps its own code: it is the one failure the UI
        // can offer a remedy for (re-authorize the account).
        $exceptions->render(function (\App\Exceptions\InstagramApiException $e, \Illuminate\Http\Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            if ($e->isPermissionError()) {
                return response()->json([
                    'message' => 'A conta do Instagram conectada não tem as permissões necessárias para esta ação. '
                        . 'Reconecte a conta concedendo todas as permissões pedidas.',
                    'code' => 'instagram_permission_required',
                ], $e->httpStatus());
            }

            return \App\Support\Errors\UpstreamError::response(
                \App\Support\Errors\UpstreamProvider::Meta,
                $e->getMessage(),
                upstreamCode: $e->metaCode(),
                status: $e->httpStatus(),
                context: ['meta_subcode' => $e->metaSubcode(), 'route' => $request->path()],
            );
        });

        // Spatie's permission middleware answers 403 with its own English
        // sentence — "User does not have the right permissions." — which the
        // dashboard printed as-is. A stable code lets the SPA say it in the
        // reader's language (lib/axios.ts). The Back Office keeps the package's
        // wording: its operators are the audience it was written for.
        $exceptions->render(function (\Spatie\Permission\Exceptions\UnauthorizedException $e, \Illuminate\Http\Request $request) {
            if (! $request->is('api/*') || $request->is('api/admin/*')) {
                return null;
            }

            return response()->json([
                'message' => 'You do not have permission to do this.',
                'code' => 'forbidden',
            ], 403);
        });
    })->create();
