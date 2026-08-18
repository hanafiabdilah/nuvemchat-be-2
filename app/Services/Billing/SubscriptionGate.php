<?php

namespace App\Services\Billing;

use App\Enums\Apiway\ApiwaySubscriptionStatus;
use App\Enums\Billing\Feature;
use App\Enums\Billing\Quota;
use App\Models\AiHubRun;
use App\Models\ApiwayInstance;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;

/**
 * Central entitlement / quota authority. Reads the tenant's current
 * subscription entitlements (snapshot, falling back to the live plan) and
 * caches them briefly for the enforcement hot path.
 *
 * API Way instances are purchased assets that grant capability on their own:
 * a tenant with live instances gets the `whatsapp_api` feature (and, with no
 * plan at all, platform access + enough connection quota to use them) even
 * when no plan says so — mode 1 of the API Way offering.
 */
class SubscriptionGate
{
    private const CACHE_TTL = 60; // seconds

    /**
     * Whether the tenant currently has platform access.
     */
    public function usable(Tenant $tenant): bool
    {
        $subscription = $tenant->currentSubscription;

        return ($subscription !== null && $subscription->isUsable())
            || $this->liveApiwayInstanceCount($tenant) > 0;
    }

    /**
     * Whether a feature flag is enabled for the tenant.
     */
    public function feature(Tenant $tenant, string $key): bool
    {
        return (bool) ($this->entitlements($tenant)['features'][$key] ?? false);
    }

    /**
     * The numeric quota for a key. Null = unlimited / not set.
     */
    public function quota(Tenant $tenant, string $key): ?int
    {
        $value = $this->entitlements($tenant)['quotas'][$key] ?? null;

        return $value === null ? null : (int) $value;
    }

    /**
     * Whether consuming one more of a quota'd resource is allowed.
     */
    public function canConsume(Tenant $tenant, string $key, int $currentCount): bool
    {
        $limit = $this->quota($tenant, $key);

        // No limit configured = unlimited.
        return $limit === null || $currentCount < $limit;
    }

    /**
     * AI Hub runs consumed in the current billing period.
     *
     * Windowed by the subscription's own period rather than the calendar month
     * so the counter resets when the customer is charged, not on the 1st. With
     * no subscription (or an open-ended comp) the calendar month is the only
     * honest fallback.
     */
    public function aiRunsUsed(Tenant $tenant): int
    {
        $subscription = $tenant->currentSubscription;
        $start = $subscription?->current_period_start ?? now()->startOfMonth();
        $end = $subscription?->current_period_end;

        return AiHubRun::query()
            ->where('tenant_id', $tenant->id)
            ->where('created_at', '>=', $start)
            ->when($end, fn ($q) => $q->where('created_at', '<=', $end))
            ->count();
    }

    /**
     * Whether the tenant may start one more AI run.
     *
     * Free when no `max_ai_runs` quota is set, which is every plan that does not
     * sell the limit — the count query only runs for plans that do.
     */
    public function canRunAi(Tenant $tenant): bool
    {
        $limit = $this->quota($tenant, Quota::MaxAiRuns->value);

        if ($limit === null) {
            return true;
        }

        return $this->aiRunsUsed($tenant) < $limit;
    }

    /**
     * @return array{quotas: array, features: array}
     */
    public function entitlements(Tenant $tenant): array
    {
        return Cache::remember(
            $this->cacheKey($tenant),
            self::CACHE_TTL,
            function () use ($tenant) {
                $subscription = $tenant->currentSubscription;
                $base = ($subscription !== null && $subscription->isUsable())
                    ? $subscription->entitlements()
                    : null;

                $instances = $this->liveApiwayInstanceCount($tenant);

                if ($base === null) {
                    if ($instances === 0) {
                        return ['quotas' => [], 'features' => []];
                    }

                    // No usable plan, but owned instances: synthesize just
                    // enough to run them. Chat & friends stay off.
                    return [
                        'quotas' => ['max_connections' => $instances],
                        'features' => [Feature::WhatsappApi->value => true],
                    ];
                }

                // Owning live instances (or having included ones in the plan)
                // implies the whatsapp_api capability even if the plan's
                // feature list doesn't spell it out.
                $includedQuota = (int) ($base['quotas']['included_instances'] ?? 0);

                if ($instances > 0 || $includedQuota > 0) {
                    $base['features'] = array_merge($base['features'] ?? [], [
                        Feature::WhatsappApi->value => true,
                    ]);
                }

                return $this->applyOverrides($tenant, $base);
            },
        );
    }

    /**
     * Layer a support-granted exception on top of the plan's entitlements.
     *
     * Applied last, so an override wins over both the plan and the API Way
     * synthesis above — that is the point of it. A tenant with no usable plan
     * still gets the merge, but `usable()` is untouched: an override tops up
     * what an account may do, it does not pay for the account. Handing out
     * platform access with no subscription is what `grantManual` is for.
     *
     * @param  array{quotas: array, features: array}  $base
     * @return array{quotas: array, features: array}
     */
    private function applyOverrides(Tenant $tenant, array $base): array
    {
        $overrides = $tenant->activeEntitlementOverrides();

        if ($overrides === null) {
            return $base;
        }

        if (is_array($overrides['features'] ?? null)) {
            // Not array_filter'd: an override explicitly setting a feature to
            // false is how support takes something away, and dropping the false
            // values would silently turn that into a no-op.
            $base['features'] = array_merge($base['features'] ?? [], $overrides['features']);
        }

        if (is_array($overrides['quotas'] ?? null)) {
            $base['quotas'] = array_merge($base['quotas'] ?? [], $overrides['quotas']);
        }

        return $base;
    }

    /**
     * Bust the cached entitlements for a tenant (call on subscription change).
     */
    public function forget(Tenant $tenant): void
    {
        Cache::forget($this->cacheKey($tenant));
    }

    /** Instances whose ProxyBR subscription is still live (not revoked/expired). */
    private function liveApiwayInstanceCount(Tenant $tenant): int
    {
        return ApiwayInstance::query()
            ->where('tenant_id', $tenant->id)
            ->whereHas('subscription', fn ($q) => $q
                ->whereIn('status', [
                    ApiwaySubscriptionStatus::Provisioning->value,
                    ApiwaySubscriptionStatus::Active->value,
                    ApiwaySubscriptionStatus::Suspended->value,
                ])
                ->where(fn ($qq) => $qq->whereNull('expires_at')->orWhere('expires_at', '>', now())))
            ->count();
    }

    private function cacheKey(Tenant $tenant): string
    {
        return "billing:entitlements:tenant:{$tenant->id}";
    }
}
