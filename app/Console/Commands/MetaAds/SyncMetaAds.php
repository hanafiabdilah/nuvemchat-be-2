<?php

namespace App\Console\Commands\MetaAds;

use App\Jobs\SyncMetaAdAccount;
use App\Models\MetaAdAccount;
use App\Services\MetaAds\MetaAdsService;
use App\Support\Heartbeat;
use Illuminate\Console\Command;

/**
 * Keep every connected ad account's costs current.
 *
 * Hourly, re-reading the last few days: Meta keeps adjusting recent costs, and
 * "today" is what people look at during the day. Accounts waiting for a new
 * login are skipped — only the person can fix those, and asking Meta again
 * only collects another refusal.
 */
class SyncMetaAds extends Command
{
    protected $signature = 'meta-ads:sync {--days= : How many days back to re-read} {--account= : Only this meta_ad_accounts id}';

    protected $description = 'Read the daily ad costs of every connected Meta ad account';

    public function handle(MetaAdsService $service): int
    {
        Heartbeat::ping('meta-ads:sync');

        if (! MetaAdsService::available()) {
            $this->info('Meta Ads is switched off or not configured on this platform.');

            return self::SUCCESS;
        }

        $days = max(1, (int) ($this->option('days') ?: MetaAdsService::SYNC_DAYS));
        $queued = 0;

        MetaAdAccount::query()
            ->with('tenant')
            ->where('enabled', true)
            ->where('status', '!=', MetaAdAccount::STATUS_REAUTH)
            ->when($this->option('account'), fn ($query, $id) => $query->whereKey($id))
            ->get()
            ->filter(fn (MetaAdAccount $account) => $account->tenant && $service->inPlan($account->tenant))
            ->each(function (MetaAdAccount $account) use ($days, &$queued) {
                SyncMetaAdAccount::dispatch($account->id, $days);
                $queued++;
            });

        $this->info("Queued {$queued} ad account(s).");

        return self::SUCCESS;
    }
}
