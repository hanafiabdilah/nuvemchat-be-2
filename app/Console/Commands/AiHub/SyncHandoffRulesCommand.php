<?php

namespace App\Console\Commands\AiHub;

use App\Models\AiHubAgent;
use App\Services\AiAgentHub\AiAgentHubTenantService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Push each agent's handoff rules to the hub again.
 *
 * Deploying the `autoDetectHumanRequest` mirror (see
 * AiAgentHubTenantService::withHandoffMirror) changes nothing on its own:
 * `handoffRules` only travels when an agent is created, edited or repushed, so
 * every agent already on the hub keeps the state it has — and the whole point
 * of the mirror is agents that are handing conversations to people against
 * their own settings. Somebody would otherwise have to open and re-save each
 * agent, which means knowing which ones and remembering why.
 *
 * Idempotent: it sends the rules this workspace already chose, so running it
 * twice is the same as running it once, and running it on an agent that was
 * already correct is a no-op the hub echoes straight back.
 *
 * Agents with no rules stored are skipped rather than given any — a PATCH that
 * invents handoff behaviour is a worse outcome than one that does nothing.
 */
class SyncHandoffRulesCommand extends Command
{
    protected $signature = 'ai-hub:sync-handoff-rules
        {--tenant= : Restrict to one workspace (local tenants.id)}
        {--agent= : Restrict to one agent, by local id or hub externalId}
        {--dry-run : Show what would be sent without calling the hub}';

    protected $description = 'Re-send handoff rules to the AI hub, so the automatic detector matches the workspace\'s setting';

    public function handle(AiAgentHubTenantService $hub): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $agents = AiHubAgent::query()
            ->with('aiHubTenant')
            ->when($this->option('tenant'), fn ($q, $id) => $q->whereHas(
                'aiHubTenant',
                fn ($t) => $t->where('tenant_id', $id)
            ))
            ->when($this->option('agent'), fn ($q, $ref) => $q->where(
                fn ($a) => $a->where('id', $ref)->orWhere('external_id', $ref)
            ))
            ->get()
            ->filter(fn (AiHubAgent $agent) => is_array($agent->handoff_rules)
                && array_key_exists('humanRequested', $agent->handoff_rules));

        if ($agents->isEmpty()) {
            $this->info('No agents with handoff rules to sync.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($agents as $agent) {
            $requested = (bool) $agent->handoff_rules['humanRequested'];
            $label = "#{$agent->id} {$agent->external_id}";

            if ($dryRun) {
                $this->line("  would send {$label}: humanRequested="
                    .var_export($requested, true)
                    .', autoDetectHumanRequest='.var_export($requested, true));

                continue;
            }

            try {
                // Through updateAgent, so the mirror is applied by the one
                // place that owns that rule rather than rebuilt here.
                $hub->updateAgent($agent, ['handoffRules' => $agent->handoff_rules]);
                $this->info("  synced {$label}");
            } catch (Throwable $th) {
                $failed++;
                $this->error("  failed {$label}: {$th->getMessage()}");
            }
        }

        if ($dryRun) {
            $this->info("{$agents->count()} agent(s) would be synced.");

            return self::SUCCESS;
        }

        $this->info(($agents->count() - $failed)." agent(s) synced, {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
