<?php

namespace App\Console\Commands\AiHub;

use App\Models\AiHubTenant;
use App\Services\AiAgentHub\AiHubAccounts;
use Illuminate\Console\Command;

/**
 * Open a hub account for every workspace that has none (see AiHubAccounts).
 *
 * `--dry-run` only reads our own database — it never calls the hub, so it is
 * safe to run in production before the flag is on, to see which mode each
 * workspace would get. Without it, nothing happens while
 * `ai_agent_hub.per_workspace_accounts` is off; it is also scheduled hourly,
 * which is how a workspace whose account could not be opened on a live
 * request gets one later.
 *
 * Rows that still hold an id from before the hub was rebuilt are reported and
 * skipped: the hub would refuse that id on every call. Clear them first with
 * `ai-hub:clear-stale-accounts`.
 */
class ProvisionHubAccountsCommand extends Command
{
    protected $signature = 'ai-hub:provision-accounts
        {--dry-run : List each workspace and the mode it would get, without calling the hub}
        {--tenant= : Restrict to one workspace (local tenants.id)}';

    protected $description = 'Open an AI hub account for each workspace that has none';

    public function handle(AiHubAccounts $accounts): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! AiHubAccounts::enabled()) {
            $this->info('Per-workspace hub accounts are off (Back Office → Integrations → AI Hub). Nothing to do.');

            return self::SUCCESS;
        }

        $scopes = AiHubTenant::query()
            ->when($this->option('tenant'), fn ($q, $id) => $q->where('tenant_id', $id))
            ->orderBy('id')
            ->get();

        if ($scopes->isEmpty()) {
            $this->info('No workspaces with an AI hub scope.');

            return self::SUCCESS;
        }

        $rows = [];
        $counts = ['provisioned' => 0, 'failed' => 0, 'existing' => 0, 'stale' => 0];

        foreach ($scopes as $scope) {
            $recorded = AiHubAccounts::recorded($scope);
            $planned = $accounts->plannedMode($scope);

            if (filled($scope->hub_tenant_id)) {
                $stale = $recorded === null;
                $counts[$stale ? 'stale' : 'existing']++;

                $rows[] = $this->row($scope, $stale ? '-' : (string) ($recorded['mode'] ?? '?'), $stale
                    ? 'stale id — run ai-hub:clear-stale-accounts'
                    : 'has account');

                continue;
            }

            if ($dryRun) {
                $rows[] = $this->row($scope, $planned, 'would open');

                continue;
            }

            $id = $accounts->ensure($scope, force: true);
            $scope->refresh();

            if ($id === null) {
                $counts['failed']++;
                $why = $scope->metadata['hub_account_attempt']['error']['reason'] ?? 'unknown';
                $rows[] = $this->row($scope, $planned, "failed: {$why}");

                continue;
            }

            $counts['provisioned']++;
            $rows[] = $this->row($scope, (string) (AiHubAccounts::recorded($scope)['mode'] ?? $planned), 'opened');
        }

        $this->table(['scope', 'tenant', 'name', 'hub account', 'mode', 'result'], $rows);

        if ($dryRun) {
            $this->info('Dry run — the hub was not called.');
        } else {
            $this->info(sprintf(
                'Opened %d, failed %d, already had one %d, stale %d.',
                $counts['provisioned'],
                $counts['failed'],
                $counts['existing'],
                $counts['stale'],
            ));
        }

        if ($counts['stale'] > 0) {
            $this->warn('Some workspaces hold an id the hub no longer has. Run ai-hub:clear-stale-accounts before turning the flag on.');
        }

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return list<string> */
    private function row(AiHubTenant $scope, string $mode, string $result): array
    {
        return [
            (string) $scope->id,
            (string) $scope->tenant_id,
            (string) $scope->name,
            (string) ($scope->hub_tenant_id ?? '-'),
            $mode,
            $result,
        ];
    }
}
