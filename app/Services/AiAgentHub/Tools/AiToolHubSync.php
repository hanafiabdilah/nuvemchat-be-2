<?php

namespace App\Services\AiAgentHub\Tools;

use App\Models\AiHubAgent;
use App\Models\ApiKey;
use App\Models\Tenant;
use App\Services\AiAgentHub\AiAgentHubTenantService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Makes sure the AI Hub has what it needs before a run names any tools.
 *
 * The hub's contract (PINGLY-TOOLS-20260928.md) keeps two things per agent,
 * and a run that names tools is refused without them:
 *
 *  1. **The tool catalog** — the six definitions from AiToolCatalog::all().
 *     Always the whole catalog, whatever the node ticked: the run names the
 *     subset, and one registration serves every node using the agent. Sent
 *     again only when the definitions in code change (compared by hash).
 *  2. **The credential the hub calls Pingly with** — a workspace API key.
 *     The hub has no platform key by design; each agent carries its own
 *     workspace's key, so no agent can reach another workspace's
 *     conversations. We issue a key named after the agent, send it once, and
 *     keep only its id: if somebody revokes it on the Developer page, the next
 *     run issues and registers a fresh one instead of failing with a 401.
 *
 * Runs on the turn's path, so both checks are local reads unless something
 * actually changed. A lock keeps two turns from issuing two keys.
 */
final class AiToolHubSync
{
    public function __construct(private AiAgentHubTenantService $hub) {}

    /** Throws when the hub refuses; the turn then fails and hands off. */
    public function ensure(AiHubAgent $agent, Tenant $tenant): void
    {
        $hash = self::catalogHash();

        if ($agent->tools_catalog_hash === $hash && $this->keyStillActive($agent)) {
            return;
        }

        Cache::lock("ai-tools-sync:{$agent->id}", 30)->block(10, function () use ($agent, $tenant, $hash) {
            $agent->refresh();

            if (! $this->keyStillActive($agent)) {
                [$key, $plain] = ApiKey::issue($tenant, 'AI Hub · '.mb_substr((string) $agent->name, 0, 60));

                $this->hub->putPinglyDelivery($agent, $plain);
                $agent->forceFill(['tools_api_key_id' => $key->id])->save();

                Log::info('AiToolHubSync: registered the Pingly credential for an agent', [
                    'ai_hub_agent_id' => $agent->id,
                    'api_key_id' => $key->id,
                ]);
            }

            if ($agent->tools_catalog_hash !== $hash) {
                $this->hub->putPinglyTools($agent, AiToolCatalog::all());
                $agent->forceFill(['tools_catalog_hash' => $hash])->save();

                Log::info('AiToolHubSync: registered the tool catalog for an agent', [
                    'ai_hub_agent_id' => $agent->id,
                    'hash' => $hash,
                ]);
            }
        });
    }

    public static function catalogHash(): string
    {
        return hash('sha256', (string) json_encode(AiToolCatalog::all()));
    }

    private function keyStillActive(AiHubAgent $agent): bool
    {
        return $agent->tools_api_key_id !== null
            && ApiKey::active()->whereKey($agent->tools_api_key_id)->exists();
    }
}
