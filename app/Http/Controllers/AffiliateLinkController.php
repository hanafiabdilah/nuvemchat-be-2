<?php

namespace App\Http\Controllers;

use App\Services\Affiliates\AffiliateLinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * GET /r/{code} — an affiliate link.
 *
 * Counts the visit, locks the visitor to the affiliator (a cookie on the
 * platform host, where signup happens), and sends them on to the marketing
 * site. A visitor already locked to someone keeps that lock (first touch); an
 * unknown or switched-off code just lands on the site, with nothing set.
 */
class AffiliateLinkController extends Controller
{
    public function __invoke(Request $request, AffiliateLinks $links, string $code): RedirectResponse
    {
        $landing = (string) config('affiliates.landing_url', 'https://pingly.com.br');
        $response = redirect()->away($landing)->header('Cache-Control', 'no-store');
        $affiliate = $links->findActive($code);

        if ($affiliate === null) {
            return $response;
        }

        $links->recordClick($affiliate, $request->ip());

        // First touch: only an absent (or dead) lock is replaced.
        if ($links->findActive($request->cookie(AffiliateLinks::COOKIE)) === null) {
            $response->withCookie(cookie(
                AffiliateLinks::COOKIE,
                $affiliate->code,
                (int) config('affiliates.cookie_days', 400) * 24 * 60,
                '/',
                null,
                $request->isSecure() || app()->isProduction(),
                true,
                false,
                'lax',
            ));
        }

        return $response;
    }
}
