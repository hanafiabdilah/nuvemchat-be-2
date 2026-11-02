<?php

namespace App\Http\Middleware;

use App\Services\Social\SocialFeatures;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a route while its platform switch is off, e.g. `social:tiktok`
 * (config/social.php).
 *
 * Not `feature:` and deliberately not tied to `services.billing.enforce`: this
 * is not about what the plan sells but about what the platform has approved,
 * and calling TikTok, YouTube or Instagram comments before that only fails
 * there instead of here.
 */
class EnsureSocialFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (SocialFeatures::enabled($feature)) {
            return $next($request);
        }

        return SocialFeatures::refusal($feature);
    }
}
