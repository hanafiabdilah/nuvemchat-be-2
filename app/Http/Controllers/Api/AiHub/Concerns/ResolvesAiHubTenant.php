<?php

namespace App\Http\Controllers\Api\AiHub\Concerns;

use App\Models\AiHubTenant;
use App\Services\AiAgentHub\AiAgentHubService;

trait ResolvesAiHubTenant
{
    /**
     * Resolve the local AI-hub scope of the authenticated user's workspace,
     * opening it if this is the first time. Idempotent, and offline: the hub
     * is not told about our workspaces, and no key is minted per workspace —
     * calls to the hub go out under the platform's own tenant token.
     */
    protected function aiHubTenant(): AiHubTenant
    {
        $tenant = auth()->user()->tenant;

        return app(AiAgentHubService::class)->createTenant($tenant);
    }
}
