<?php

namespace App\Http\Middleware;

use App\Services\Market\MarketCapabilities;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a route behind what the workspace's country may buy, e.g.
 * `capability:virtual_numbers`.
 *
 * ⚠️ Unlike `feature:`, this does NOT honour `services.billing.enforce`. That
 * switch turns off *billing* enforcement, and a country gate is not billing:
 * with it respected, any environment running without enforcement would happily
 * sell Brazilian SMS numbers to an Indonesian workspace, which is not a
 * discount — it is a purchase that can never be delivered.
 */
class EnsureMarketCapability
{
    public function handle(Request $request, Closure $next, string $capability): Response
    {
        $tenant = $request->user()?->tenant;

        // No workspace yet (mid-registration) or the country allows it.
        if ($tenant === null || MarketCapabilities::allows($tenant->market_code, $capability)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'This is not available in your country.',
            'code' => 'not_available_in_market',
            'capability' => $capability,
        ], 403);
    }
}
