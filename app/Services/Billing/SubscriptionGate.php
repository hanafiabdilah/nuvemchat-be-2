<?php

namespace App\Services\Billing;

use App\Enums\Apiway\ApiwaySubscriptionStatus;
use App\Enums\Billing\Feature;
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

                return $base;
            },
        );
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
