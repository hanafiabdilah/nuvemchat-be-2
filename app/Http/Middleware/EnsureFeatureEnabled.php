<?php

namespace App\Http\Middleware;

use App\Services\Billing\SubscriptionGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a route behind a plan feature flag, e.g. `feature:flow`.
 */
class EnsureFeatureEnabled
{
    public function __construct(
        protected SubscriptionGate $gate,
    ) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (! config('services.mercadopago.enforce')) {
            return $next($request);
        }

        $tenant = $request->user()?->tenant;

        // No tenant (super-admin) or feature enabled → allow.
        if ($tenant === null || $this->gate->feature($tenant, $feature)) {
            return $next($request);
        }

        return response()->json([
            'message' => "This feature ({$feature}) is not included in your current plan.",
            'code' => 'feature_not_in_plan',
            'feature' => $feature,
        ], 403);
    }
}
