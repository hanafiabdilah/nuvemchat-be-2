<?php

namespace App\Services\Billing;

use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;

/**
 * Central entitlement / quota authority. Reads the tenant's current
 * subscription entitlements (snapshot, falling back to the live plan) and
 * caches them briefly for the enforcement hot path.
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

        return $subscription !== null && $subscription->isUsable();
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
            fn () => $tenant->currentSubscription
                ? $tenant->currentSubscription->entitlements()
                : ['quotas' => [], 'features' => []],
        );
    }

    /**
     * Bust the cached entitlements for a tenant (call on subscription change).
     */
    public function forget(Tenant $tenant): void
    {
        Cache::forget($this->cacheKey($tenant));
    }

    private function cacheKey(Tenant $tenant): string
    {
        return "billing:entitlements:tenant:{$tenant->id}";
    }
}
