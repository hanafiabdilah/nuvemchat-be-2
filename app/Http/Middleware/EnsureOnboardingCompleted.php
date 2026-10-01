<?php

namespace App\Http\Middleware;

use App\Services\Onboarding\OnboardingState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every new workspace passes the first-run guide before the dashboard.
 *
 * The dashboard redirects to /onboarding on its own (mustOnboard in the
 * router), but a redirect in the browser is a suggestion; this is the rule.
 * Until the workspace pays for a plan, rents a number or skips the guide (see
 * OnboardingState), its API answers 403 `onboarding_required` everywhere except
 * what the guide itself needs: billing and plans (the plan path and its
 * checkout), numbers and credits (the numbers path and the balance that pays
 * for it), the guide's own skip, and the account endpoints every screen reads.
 *
 * Behind the same BILLING_ENFORCE switch as EnsureSubscriptionActive: the guide
 * is how a workspace starts paying, and with billing off there is nothing to
 * choose — and no reason to stand between a workspace and its dashboard.
 *
 * Runs before subscription.active, so a workspace that has not chosen yet is
 * told that, rather than that a subscription it never had is "suspended".
 */
class EnsureOnboardingCompleted
{
    /** Route-name prefixes the guide needs. */
    private const EXEMPT_PREFIXES = ['billing.', 'plans.', 'numbers.', 'credits.', 'onboarding.'];

    /**
     * Route URIs (relative) the guide needs: the user payload that says whether
     * it is still required, the account self-service every screen touches
     * (heartbeat, preferences, avatar), and the workspace's own settings.
     */
    private const EXEMPT_URI_PREFIXES = ['api/user', 'api/workspace'];

    public function __construct(
        protected OnboardingState $onboarding,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('services.billing.enforce') || $this->isExempt($request)) {
            return $next($request);
        }

        $tenant = $request->user()?->tenant;

        // No workspace yet (mid-registration) — nothing to onboard.
        if ($tenant === null || ! $this->onboarding->required($tenant)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Choose how you want to start before using the dashboard.',
            'code' => 'onboarding_required',
        ], 403);
    }

    protected function isExempt(Request $request): bool
    {
        $name = $request->route()?->getName() ?? '';
        foreach (self::EXEMPT_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        $uri = $request->route()?->uri() ?? '';
        foreach (self::EXEMPT_URI_PREFIXES as $prefix) {
            if ($uri === $prefix || str_starts_with($uri, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }
}
