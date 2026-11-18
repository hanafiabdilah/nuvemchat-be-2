<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API answers are not to be kept by the browser.
 *
 * Laravel's default `Cache-Control: no-cache, private` only asks the browser to
 * revalidate before reusing a response — it still lets the browser write the
 * response to its disk cache. For this API that response is a conversation, a
 * contact list, a customer's phone number, and it stayed on the disk of a
 * shared computer after sign-out. `no-store` is the directive that forbids
 * keeping it at all.
 *
 * Costs nothing: no API response sends a validator (ETag / Last-Modified), so
 * `no-cache` was already a full refetch every time.
 *
 * Only JSON under /api and /widget-api, and only when the controller did not
 * state a caching policy of its own — a response that says `public` or carries
 * a max-age chose to be cached, and a stream (the flow assistant's SSE) sets
 * its own headers.
 *
 * ⚠️ Registered as global middleware, not in the `api` group: `auth` sorts
 * ahead of group middleware, so a refusal (401) would come back without ever
 * passing through here.
 */
class PreventApiResponseCaching
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->is('api/*', 'widget-api', 'widget-api/*')) {
            return $response;
        }

        if (! str_contains((string) $response->headers->get('Content-Type'), 'json')) {
            return $response;
        }

        $headers = $response->headers;
        $choseCaching = $headers->hasCacheControlDirective('public')
            || $headers->hasCacheControlDirective('max-age')
            || $headers->hasCacheControlDirective('s-maxage');

        if (! $choseCaching) {
            $headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
