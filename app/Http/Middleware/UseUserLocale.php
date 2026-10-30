<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answer in the signed-in person's language: their own choice, else their
 * workspace market's (User::localeCode()).
 *
 * Opt-in per route group rather than global on purpose. Most of the API still
 * writes its messages by hand, and switching every `__()` at once would also
 * switch validation messages the dashboard has been showing in one language
 * for years. The CRM is the first surface written with `__()` throughout —
 * its errors, and the names of the default pipeline it creates on first use,
 * should read in the workspace's language, not the server's.
 */
class UseUserLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->localeCode();

        if ($locale && array_key_exists($locale, (array) config('markets.locales', []))) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
