<?php

namespace App\Console\Commands\AiHub;

use App\Models\AiHubTenant;
use App\Services\AiAgentHub\AiHubAccounts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Clear the hub account ids left from before the hub was rebuilt.
 *
 * Three rows of `ai_hub_tenants` still hold the id of the hub tenant they had
 * before 2026-09-01. The hub no longer has them, and with per-workspace
 * accounts on, AiHubAccounts would send them as `X-Hub-Tenant` and every call
 * for those workspaces would be refused.
 *
 * An id counts as stale when this application did not open it: every account
 * opened by {@see AiHubAccounts} is recorded under `metadata.hub_account`, and
 * the old ids predate that. So the check needs no call to the hub — which
 * keeps it safe while the flag is off.
 *
 * The whole table is written to `storage/app/backups/` before anything
 * changes, and the before and after are printed.
 */
class ClearStaleHubAccountsCommand extends Command
{
    protected $signature = 'ai-hub:clear-stale-accounts
        {--dry-run : Show what would be cleared without changing anything}';

    protected $description = 'Clear AI hub account ids this application did not open (pre-rebuild leftovers)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $all = AiHubTenant::query()->orderBy('id')->get();
        $stale = $all->filter(fn (AiHubTenant $scope) => filled($scope->hub_tenant_id)
            && AiHubAccounts::recorded($scope) === null);

        $this->info('Before:');
        $this->table(['scope', 'tenant', 'hub_tenant_id', 'stale'], $all
            ->filter(fn (AiHubTenant $scope) => filled($scope->hub_tenant_id))
            ->map(fn (AiHubTenant $scope) => [
                $scope->id,
                $scope->tenant_id,
                $scope->hub_tenant_id,
                $stale->contains('id', $scope->id) ? 'yes' : 'no',
            ])
            ->values()
            ->all());

        if ($stale->isEmpty()) {
            $this->info('No stale hub account ids.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info(sprintf('Dry run — %d id(s) would be cleared.', $stale->count()));

            return self::SUCCESS;
        }

        // storage/app is the volume that survives a deploy; the rest of the
        // container does not.
        $path = storage_path('app/backups/ai_hub_tenants-'.now()->format('Ymd-His').'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode(
            $all->map(fn (AiHubTenant $scope) => $scope->getAttributes())->values()->all(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));

        $this->info('Backup written to '.$path);

        foreach ($stale as $scope) {
            $scope->update(['hub_tenant_id' => null]);
        }

        $this->info('After:');
        $this->table(['scope', 'tenant', 'hub_tenant_id'], $stale
            ->map(fn (AiHubTenant $scope) => [$scope->id, $scope->tenant_id, $scope->fresh()->hub_tenant_id ?? 'NULL'])
            ->values()
            ->all());

        $this->info(sprintf('Cleared %d stale id(s).', $stale->count()));

        return self::SUCCESS;
    }
}
