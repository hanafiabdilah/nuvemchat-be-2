<?php

namespace App\Http\Controllers\Api\AiHub\Concerns;

use App\Models\AiHubTenant;
use App\Services\AiAgentHub\AiAgentHubService;

trait ResolvesAiHubTenant
{
    /**
     * Resolve the AiHubTenant for the authenticated user, provisioning it
     * (and an active API key) on the hub if missing. Idempotent.
     */
    protected function aiHubTenant(): AiHubTenant
    {
        $tenant = auth()->user()->tenant;

        $service = app(AiAgentHubService::class);
        $aiHubTenant = $service->createTenant($tenant);
        $service->createApiKey($aiHubTenant);

        return $aiHubTenant->fresh();
    }
}
