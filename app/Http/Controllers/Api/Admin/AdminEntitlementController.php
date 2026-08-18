<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Billing\Feature;
use App\Enums\Billing\Quota;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Services\Billing\SubscriptionGate;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Back Office: per-tenant exceptions to what the plan allows.
 *
 * Support routinely needs to say "give this account the funnel for a month
 * while they evaluate it" or "raise their connection cap during the migration".
 * The only tools for that were inventing a private plan or comping a whole
 * subscription — both of which throw away the customer's real billing state to
 * express a temporary exception.
 *
 * An override sits on top of the plan and expires on its own. It never grants
 * platform access: a suspended account stays suspended, because that decision
 * is about whether the customer is paying, not about what their tier includes.
 * Comping an account is still `POST /customers/{tenant}/subscription`.
 */
class AdminEntitlementController extends Controller
{
    public function __construct(
        private SubscriptionGate $gate,
    ) {}

    /**
     * What this tenant is entitled to, and which part of it is an exception.
     *
     * Returns the effective set *and* the override separately — an operator
     * needs to see both "they have CRM" and "they have CRM because we granted
     * it until the 30th", which a merged view cannot express.
     */
    public function show(Tenant $tenant)
    {
        $tenant->load('currentSubscription.plan');
        $overrides = $tenant->entitlement_overrides;

        return response()->json([
            'data' => [
                'effective' => $this->gate->entitlements($tenant),
                'plan' => [
                    'name' => $tenant->currentSubscription?->plan?->name,
                    'features' => $tenant->currentSubscription?->plan?->features ?? [],
                    'quotas' => $tenant->currentSubscription?->plan?->quotas ?? [],
                ],
                'override' => $overrides,
                // Expiry is evaluated on read rather than cleared, so an expired
                // grant is still on the record — the UI needs to distinguish
                // "no exception" from "the exception ran out on the 3rd".
                'override_active' => $tenant->activeEntitlementOverrides() !== null,
                'usage' => [
                    'connections' => $tenant->connections()->count(),
                    'agents' => $tenant->users()->count(),
                    'ai_runs' => $this->gate->aiRunsUsed($tenant),
                ],
            ],
        ]);
    }

    public function update(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'features' => ['nullable', 'array:'.implode(',', Feature::values())],
            'features.*' => ['boolean'],
            'quotas' => ['nullable', 'array:'.implode(',', Quota::values())],
            'quotas.*' => ['nullable', 'integer', 'min:0'],
            // Open-ended is allowed but should be rare: an exception nobody
            // ever revisits is just an undocumented plan.
            'expires_at' => ['nullable', 'date', 'after:now'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $features = array_filter($data['features'] ?? [], fn ($v) => $v !== null);
        $quotas = array_filter($data['quotas'] ?? [], fn ($v) => $v !== null);

        if ($features === [] && $quotas === []) {
            return response()->json([
                'message' => 'An override must change at least one feature or quota.',
            ], 422);
        }

        $overrides = [
            'features' => $features,
            'quotas' => $quotas,
            'expires_at' => isset($data['expires_at'])
                ? Carbon::parse($data['expires_at'])->toIso8601String()
                : null,
            'note' => $data['note'] ?? null,
            'granted_by' => $request->user()?->id,
            'granted_by_name' => $request->user()?->name,
            'granted_at' => now()->toIso8601String(),
        ];

        $tenant->update(['entitlement_overrides' => $overrides]);

        // Entitlements are cached for the enforcement hot path; without this the
        // grant appears to do nothing for up to a minute, which reads as a bug
        // and invites a second grant on top.
        $this->gate->forget($tenant);

        AuditLog::record(
            'entitlements.grant',
            "Granted entitlement override to tenant #{$tenant->id}",
            $overrides + ['tenant_id' => $tenant->id],
        );

        return $this->show($tenant->fresh());
    }

    public function destroy(Request $request, Tenant $tenant)
    {
        $previous = $tenant->entitlement_overrides;

        $tenant->update(['entitlement_overrides' => null]);
        $this->gate->forget($tenant);

        AuditLog::record(
            'entitlements.revoke',
            "Removed entitlement override from tenant #{$tenant->id}",
            ['tenant_id' => $tenant->id, 'previous' => $previous],
        );

        return $this->show($tenant->fresh());
    }
}
