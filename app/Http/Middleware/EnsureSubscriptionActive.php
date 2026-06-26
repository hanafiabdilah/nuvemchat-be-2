<?php

namespace App\Http\Middleware;

use App\Services\Billing\SubscriptionGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks tenants whose subscription is not usable (suspended / expired).
 * Billing, auth and account routes stay open so the tenant can still pay.
 */
class EnsureSubscriptionActive
{
    /**
     * Route-name prefixes that remain accessible while suspended.
     */
    private const EXEMPT_PREFIXES = ['billing.', 'plans.'];

    /**
     * Exact route URIs (relative) that remain accessible.
     */
    private const EXEMPT_URIS = ['api/user', 'api/uploads'];

    public function __construct(
        protected SubscriptionGate $gate,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Master switch for the enforcement rollout.
        if (! config('services.mercadopago.enforce')) {
            return $next($request);
        }

        if ($this->isExempt($request)) {
            return $next($request);
        }

        $user = $request->user();
        $tenant = $user?->tenant;

        // Platform super-admins (no tenant) are never gated here.
        if ($tenant === null) {
            return $next($request);
        }

        if (! $this->gate->usable($tenant)) {
            return response()->json([
                'message' => 'Your subscription is suspended. Please update your billing to continue.',
                'code' => 'subscription_suspended',
            ], 403);
        }

        return $next($request);
    }

    protected function isExempt(Request $request): bool
    {
        $name = $request->route()?->getName() ?? '';
        foreach (self::EXEMPT_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return in_array($request->route()?->uri(), self::EXEMPT_URIS, true);
    }
}
