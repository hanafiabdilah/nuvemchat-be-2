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
 * What remains is mostly local. An `AiHubTenant` row is a scope: it owns the
 * workspace's agents, provider credentials and runs in our own database.
 *
 * With `ai_agent_hub.per_workspace_accounts` on, the scope also gets its own
 * account *inside our hub tenant* — opened by the hub's platform API with the
 * same platform token, never with an admin token — and its id is stored in
 * `hub_tenant_id` (see {@see AiHubAccounts}). Off, nothing here calls the hub.
 * Either way a workspace is never blocked by the hub: an account that cannot
 * be opened is retried later and calls go on without it.
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
        $scope = $this->openScope($tenant);

        // Outside the scope lock: opening the hub account takes its own lock
        // and may wait on the network. No-op while the flag is off.
        app(AiHubAccounts::class)->ensure($scope);

        return $scope;
    }

    protected function openScope(Tenant $tenant): AiHubTenant
    {
        return Cache::lock($this->tenantLockKey($tenant), self::LOCK_TTL)
            ->block(self::LOCK_WAIT, function () use ($tenant) {
                $existing = $tenant->aiHubTenant()->first();

                if ($existing) {
                    return $existing;
                }

                $identifier = $this->buildTenantIdentifier($tenant);

                return $tenant->aiHubTenant()->create([
                    // Null until the workspace's hub account is opened (only
                    // with per-workspace accounts on — see AiHubAccounts).
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
