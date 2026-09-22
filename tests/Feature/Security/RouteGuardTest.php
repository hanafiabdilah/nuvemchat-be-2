<?php

use Illuminate\Support\Facades\Route;

/**
 * The boundary, asserted rather than assumed.
 *
 * ⚠️ This platform has no global tenant scope. There is no model-level rule
 * that says "a query only ever sees this workspace" — isolation is carried by
 * Conversation::visibleTo(), isAccessibleBy() and the connection_user pivot,
 * applied by hand at every read path. Retrofitting a global scope now is a
 * large and genuinely risky change (every aggregate, every webhook resolving a
 * connection, every Back Office query deliberately crossing tenants), so it is
 * not what this does.
 *
 * What it does is pin the one thing underneath all of that: nothing under /api
 * reaches a controller without either a signed-in user or an API key, unless it
 * is on the short list below. Every scoping rule in the application assumes a
 * caller; a route added outside the auth group has no caller to scope to, and
 * that is how tenant isolation actually gets lost in practice — not by somebody
 * rewriting visibleTo(), but by a new endpoint landing in the wrong group.
 *
 * A test rather than a document because the failure mode is silence: an
 * unauthenticated endpoint looks exactly like an authenticated one until
 * somebody tries it without a token.
 */

/**
 * Middleware with aliases resolved to classes, the way `route:list` reports it.
 *
 * ⚠️ Not `$route->gatherMiddleware()`, which hands back the aliases as written
 * (`super-admin`, `auth:sanctum`). Matching on those would make this suite pass
 * the day somebody renames an alias and leaves the route unguarded.
 */
function routeMiddleware($route): string
{
    return implode(' ', app('router')->gatherRouteMiddleware($route));
}

/**
 * Endpoints that answer before there is anyone to authenticate.
 *
 * Adding a line here is a deliberate act. Each of these is either part of
 * getting a credential (sign-in, the second factor, password recovery,
 * registration, redeeming an impersonation token) or is public by design — and
 * each carries its own defence: a throttle, a signed token, or an OTP. If a new
 * entry does not fit one of those descriptions, it belongs behind auth.
 *
 * @var list<string>
 */
$public = [
    'api/admin/auth/login',
    'api/admin/auth/two-factor-challenge',
    'api/auth/login',
    'api/auth/two-factor-challenge',
    'api/auth/register',
    'api/auth/password/forgot',
    'api/auth/password/reset',
    'api/auth/password/verify',
    'api/impersonate/redeem',
    'api/public/bootstrap',
];

it('lets nothing under /api reach a controller without a caller', function () use ($public) {
    $unguarded = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => $route->uri() === 'api' || str_starts_with($route->uri(), 'api/'))
        ->reject(function ($route) {
            $middleware = routeMiddleware($route);

            return str_contains($middleware, 'Authenticate:sanctum')
                || str_contains($middleware, 'V1\\ApiKeyAuth');
        })
        ->map(fn ($route) => $route->uri())
        ->unique()
        ->values()
        ->all();

    expect(array_values(array_diff($unguarded, $public)))->toBe([]);
});

it('keeps every Back Office endpoint behind the super-admin gate', function () use ($public) {
    // /api/admin is the surface that reaches every workspace on the platform.
    // Signing in is the only thing there that can precede the check.
    $ungated = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/admin'))
        ->reject(fn ($route) => str_contains(routeMiddleware($route), 'EnsureUserIsSuperAdmin'))
        ->map(fn ($route) => $route->uri())
        ->unique()
        ->values()
        ->all();

    expect(array_values(array_diff($ungated, $public)))->toBe([]);
});

it('does not leave a public endpoint without a rate limit', function () use ($public) {
    // Every one of these can be called by anybody, and most of them write —
    // a user, an OTP, a WhatsApp message the platform is billed for.
    $unlimited = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array($route->uri(), $public, true))
        ->reject(fn ($route) => str_contains(routeMiddleware($route), 'ThrottleRequests'))
        ->map(fn ($route) => $route->methods()[0].' '.$route->uri())
        ->values()
        ->all();

    expect($unlimited)->toBe([]);
});
