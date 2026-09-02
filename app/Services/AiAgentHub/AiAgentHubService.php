<?php

namespace App\Services\AiAgentHub;

use App\Models\AiHubTenant;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves the local AI-hub scope of a customer workspace.
 *
 * This class used to register each workspace as its own tenant on the hub,
 * through `POST /admin/tenants` and `POST /admin/tenants/{id}/api-keys`, using
 * an admin token held in platform settings. That was the wrong shape: the hub's
 * admin API belongs to whoever operates the hub. **Pingly is a tenant there,
 * not an operator** — it holds one tenant token (see {@see AiAgentHubConfig})
 * and every call it makes is that single tenant's.
 *
 * What remains is purely local. An `AiHubTenant` row is a scope: it owns the
 * workspace's agents, provider credentials and runs in our own database, and
 * the hub neither knows nor needs to know it exists. No network call is made
 * here — which is also why a new workspace can no longer be blocked by the hub
 * refusing a credential we should never have been sending.
 */
class AiAgentHubService
{
    /** Seconds the lock holder can keep the lock before it auto-releases. */
    protected const LOCK_TTL = 30;

    /** Seconds to wait when blocking on the lock before giving up. */
    protected const LOCK_WAIT = 10;

    /**
     * Get (or open) the workspace's local AI-hub scope. Idempotent, and
     * locked per workspace so two concurrent requests cannot open two scopes.
     */
    public function createTenant(Tenant $tenant): AiHubTenant
    {
        return Cache::lock($this->tenantLockKey($tenant), self::LOCK_TTL)
            ->block(self::LOCK_WAIT, function () use ($tenant) {
                $existing = $tenant->aiHubTenant()->first();

                if ($existing) {
                    return $existing;
                }

                $identifier = $this->buildTenantIdentifier($tenant);

                return $tenant->aiHubTenant()->create([
                    // Deliberately null: this row is ours, and there is no hub
                    // tenant behind it. Rows created before this correction
                    // keep the id of the hub tenant they once had.
                    'hub_tenant_id' => null,
                    'external_id' => $identifier,
                    'name' => $identifier,
                    'status' => 'ACTIVE',
                ]);
            });
    }

    /**
     * Build the `{app_name}_{tenant_id}` identifier used as the scope label and
     * as the namespace for the external ids we send with agents.
     */
    public function buildTenantIdentifier(Tenant $tenant): string
    {
        $appName = (string) config('app.name');

        return "{$appName}_{$tenant->id}";
    }

    protected function tenantLockKey(Tenant $tenant): string
    {
        return "ai_hub:create_tenant:{$tenant->id}";
    }
}
