<?php

namespace App\Jobs;

use App\Models\MetaAdAccount;
use App\Services\MetaAds\MetaAdsException;
use App\Services\MetaAds\MetaAdsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Read one ad account's daily ad costs from Meta.
 *
 * A failure is recorded on the account (status + last_error), which is what
 * the Integrations page and the Health page show — so the job itself does
 * not throw it: a retry of a token Meta refused only spends another refusal,
 * and the next scheduled run tries again anyway.
 */
class SyncMetaAdAccount implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Up to a dozen paged Graph calls on a first, 90-day read. */
    public int $timeout = 300;

    public function __construct(
        public int $accountId,
        public int $days = MetaAdsService::SYNC_DAYS,
    ) {}

    public function handle(MetaAdsService $service): void
    {
        $account = MetaAdAccount::with('tenant')->find($this->accountId);

        if (! $account || ! $account->enabled || ! $account->tenant || ! $service->inPlan($account->tenant)) {
            return;
        }

        try {
            $rows = $service->sync($account, $this->days);

            Log::info('SyncMetaAdAccount: ad costs read', [
                'account_id' => $account->account_id,
                'tenant_id' => $account->tenant_id,
                'days' => $this->days,
                'rows' => $rows,
            ]);
        } catch (MetaAdsException $e) {
            // Already on the account; see the class docblock.
        }
    }
}
