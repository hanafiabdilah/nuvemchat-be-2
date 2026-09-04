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
     * Route-name prefixes that remain accessible while suspended. `apiway.`
     * stays open so a tenant with no (or lapsed) plan can still buy and manage
     * unit-purchased API Way instances — mode 1 of that offering. `numbers.`
     * for the same reason, plus one of its own: a rented number keeps costing
     * the platform money every month, so locking the customer out of the screen
     * that cancels it would be charging them for a page they cannot open.
     * `gallery.` is exempt on that second ground alone: rented storage renews
     * every month from the balance, so the screen that stops it has to stay
     * reachable — and a suspended workspace that cannot see its own files
     * cannot decide which ones to delete to get back under the limit.
     */
    private const EXEMPT_PREFIXES = ['billing.', 'plans.', 'apiway.', 'numbers.', 'gallery.'];

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

        // A user without a tenant: mid-registration, before the workspace row
        // exists. (Back Office admins are their own model and never reach
        // this middleware — see EnsureUserIsSuperAdmin.)
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
