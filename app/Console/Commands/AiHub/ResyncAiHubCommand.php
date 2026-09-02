<?php

namespace App\Console\Commands\AiHub;

use App\Models\AiHubAgent;
use App\Models\AiHubTenant;
use App\Services\AiAgentHub\AiAgentHubTenantService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Rebuild hub-side objects that the hub no longer has, from our local mirror.
 *
 * Written for the day the hub came back empty after a ransomware reset: every
 * id we held stopped resolving, so editing an agent answered 404 and creating
 * one answered "Provider credential not found". Nothing was lost on our side —
 * prompts, profiles, knowledge, skills and training examples are all mirrored
 * here — so most of it can simply be pushed back.
 *
 * The one thing that cannot: **provider API keys**. They are forwarded to the
 * hub and deliberately never stored locally (we keep only `keyPreview`), so a
 * missing credential has to be entered again by the workspace that owns it.
 * The command reports those instead of pretending it can fix them — and after
 * a breach they should be *rotated at the provider*, not merely re-pasted.
 */
class ResyncAiHubCommand extends Command
{
    protected $signature = 'ai-hub:resync
        {--tenant= : Restrict to one workspace (local tenants.id)}
        {--dry-run : Report what is missing without writing anything}';

    protected $description = 'Re-push agents and their training to the AI hub from the local mirror';

    public function handle(AiAgentHubTenantService $hub): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $scopes = AiHubTenant::query()
            ->when($this->option('tenant'), fn ($q, $id) => $q->where('tenant_id', $id))
            ->orderBy('id')
            ->get();

        if ($scopes->isEmpty()) {
            $this->warn('No AI hub scopes found.');

            return self::SUCCESS;
        }

        $needReentry = [];
        $repushed = 0;
        $failed = 0;

        foreach ($scopes as $scope) {
            $this->line("<options=bold>Workspace #{$scope->tenant_id}</> (scope {$scope->id})");

            try {
                $hubCredentialIds = $this->ids($hub->listProviderCredentials($scope));
                $hubAgentIds = $this->ids($hub->listAgents($scope));
            } catch (Throwable $e) {
                $this->error("  could not read the hub: {$e->getMessage()}");
                $failed++;

                continue;
            }

            foreach ($scope->providerCredentials as $credential) {
                if (in_array($credential->hub_provider_credential_id, $hubCredentialIds, true)) {
                    continue;
                }

                $needReentry[] = "#{$scope->tenant_id}  {$credential->provider}  {$credential->name}  ({$credential->key_preview})";
                $this->warn("  credential '{$credential->name}' is gone from the hub — the API key must be entered again");
            }

            $agents = AiHubAgent::query()
                ->with(['providerCredential', 'profile', 'knowledge', 'skills', 'trainingExamples'])
                ->where('ai_hub_tenant_id', $scope->id)
                ->get();

            foreach ($agents as $agent) {
                $onHub = in_array($agent->hub_agent_id, $hubAgentIds, true);

                // An agent is created *against* a credential, so a workspace
                // that has not re-entered its key yet cannot have its agents
                // back — and saying so beats a 400 from the hub.
                if (! $onHub) {
                    $credentialId = $agent->providerCredential?->hub_provider_credential_id;

                    if (! $credentialId || ! in_array($credentialId, $hubCredentialIds, true)) {
                        $this->warn("  agent '{$agent->name}' is waiting on its provider credential — re-enter the key first, then run this again");

                        continue;
                    }
                }

                if ($dryRun) {
                    $pending = $agent->knowledge->count() + $agent->skills->count() + $agent->trainingExamples->count();
                    $this->line($onHub
                        ? "  would check agent '{$agent->name}' and re-push whichever of its {$pending} training item(s) the hub is missing"
                        : "  would re-push agent '{$agent->name}' + {$pending} training item(s)");

                    continue;
                }

                try {
                    $sent = $this->repush($hub, $agent, $onHub);
                    $repushed++;
                    $this->info($onHub
                        ? "  agent '{$agent->name}' was already on the hub; re-pushed {$sent} missing training item(s)"
                        : "  re-pushed agent '{$agent->name}' + {$sent} training item(s)");
                } catch (Throwable $e) {
                    $this->error("  agent '{$agent->name}' failed: {$e->getMessage()}");
                    $failed++;
                }
            }
        }

        if ($needReentry) {
            $this->newLine();
            $this->line('<options=bold>Provider credentials to enter again</> (their keys were never stored here):');
            foreach ($needReentry as $row) {
                $this->line("  {$row}");
            }
        }

        $this->newLine();
        $this->line($dryRun
            ? 'Dry run — nothing was written.'
            : "Re-pushed {$repushed} agent(s); {$failed} failure(s).");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Push one agent and everything hanging off it. The agent goes first: its
     * new hub id is the address the training items are posted to.
     *
     * Each item is checked against what the hub actually holds rather than
     * pushed blindly, which is what makes a second run finish a first one that
     * died halfway. Without it an agent that got created but lost its last few
     * knowledge items would read as "already on the hub" forever, and the gap
     * would be invisible — an agent answering from half its material.
     *
     * @return int  how many training items were sent
     */
    protected function repush(AiAgentHubTenantService $hub, AiHubAgent $agent, bool $onHub): int
    {
        if (! $onHub) {
            $hub->repushAgent($agent);
        }

        // A PUT, so it is safe either way, and cheaper than reading it first.
        if ($profile = $agent->profile) {
            $hub->setAgentProfile($agent, array_filter([
                'language' => $profile->language,
                'tone' => $profile->tone,
                'responseStyle' => $profile->response_style,
                'instructions' => $profile->instructions,
                'limits' => $profile->limits,
                'metadata' => $profile->metadata,
            ], fn ($v) => $v !== null));
        }

        // A freshly created agent has nothing on the hub yet, so skip the reads.
        $haveKnowledge = $onHub ? $this->ids($hub->listAgentKnowledge($agent)) : [];
        $haveSkills = $onHub ? $this->ids($hub->listAgentSkills($agent)) : [];
        $haveExamples = $onHub ? $this->ids($hub->listAgentTrainingExamples($agent)) : [];

        $sent = 0;

        foreach ($agent->knowledge as $knowledge) {
            if (! in_array($knowledge->hub_knowledge_id, $haveKnowledge, true)) {
                $hub->repushKnowledge($knowledge);
                $sent++;
            }
        }

        foreach ($agent->skills as $skill) {
            if (! in_array($skill->hub_skill_id, $haveSkills, true)) {
                $hub->repushSkill($skill);
                $sent++;
            }
        }

        foreach ($agent->trainingExamples as $example) {
            if (! in_array($example->hub_example_id, $haveExamples, true)) {
                $hub->repushTrainingExample($example);
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * The hub answers some collections bare and some wrapped in `data`.
     *
     * @return array<int, string>
     */
    protected function ids(array $payload): array
    {
        $rows = $payload['data'] ?? $payload;

        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($row) => is_array($row) ? ($row['id'] ?? null) : null,
            $rows
        )));
    }
}
