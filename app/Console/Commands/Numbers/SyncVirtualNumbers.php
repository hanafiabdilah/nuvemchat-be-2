<?php

namespace App\Console\Commands\Numbers;

use App\Services\VirtualNumbers\ApiwayNumbersConfig;
use App\Services\VirtualNumbers\VirtualNumberService;
use App\Support\Heartbeat;
use Illuminate\Console\Command;

/**
 * Reconcile the local rows with what API Way actually has on the account.
 *
 * Four jobs, and every one of them is about money that would otherwise move
 * without anybody noticing:
 *  - refresh `renews_at`, which is the deadline numbers:renew works from;
 *  - adopt numbers that exist upstream but whose purchase never confirmed —
 *    the reason charging before provisioning is safe against a timeout;
 *  - refund purchases that stalled long enough to be certain nothing was
 *    delivered;
 *  - report numbers the platform is billed for that no workspace owns.
 */
class SyncVirtualNumbers extends Command
{
    protected $signature = 'numbers:sync';

    protected $description = 'Reconcile rented virtual numbers with API Way';

    public function handle(VirtualNumberService $numbers): int
    {
        Heartbeat::ping('numbers:sync');

        if (! ApiwayNumbersConfig::isConfigured()) {
            $this->warn('API Way numbers credentials are not configured — nothing to do.');

            return self::SUCCESS;
        }

        $result = $numbers->syncStatuses();

        $this->info(sprintf(
            'Synced: %d, adopted: %d, refunded: %d, cancelled upstream: %d, orphans: %d.',
            $result['synced'],
            $result['adopted'],
            $result['refunded'],
            $result['cancelled'],
            $result['orphans'],
        ));

        return self::SUCCESS;
    }
}
