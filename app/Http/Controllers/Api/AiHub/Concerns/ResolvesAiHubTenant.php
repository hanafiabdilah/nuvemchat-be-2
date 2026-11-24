<?php

namespace App\Http\Controllers\Api\AiHub\Concerns;

use App\Models\AiHubTenant;
use App\Services\AiAgentHub\AiAgentHubService;

trait ResolvesAiHubTenant
{
    /**
     * Resolve the local AI-hub scope of the authenticated user's workspace,
     * opening it if this is the first time. Idempotent. No key is minted per
     * workspace — calls go out under the platform's own tenant token — but
     * with per-workspace accounts on, this is also where the workspace's hub
     * account is opened (AiAgentHubService::createTenant → AiHubAccounts).
     */
    protected function aiHubTenant(): AiHubTenant
    {
        $tenant = auth()->user()->tenant;

        return app(AiAgentHubService::class)->createTenant($tenant);
    }
}
