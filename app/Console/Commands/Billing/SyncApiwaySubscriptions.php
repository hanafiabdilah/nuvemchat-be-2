<?php

namespace App\Console\Commands\Billing;

use App\Services\Connection\Apiway\ApiwayPartnerClient;
use App\Services\Connection\Apiway\ApiwayService;
use App\Support\Heartbeat;
use Illuminate\Console\Command;

/**
 * Mirrors ProxyBR's hourly no-grace revoke: expires local rows past their
 * expiry (releasing linked connections, voiding open invoices, notifying the
 * tenant) and reconciles remaining state from the partner API best-effort.
 */
class SyncApiwaySubscriptions extends Command
{
    protected $signature = 'apiway:sync';

    protected $description = 'Expire overdue API Way subscriptions and reconcile state with ProxyBR';

    public function handle(ApiwayService $apiway, ApiwayPartnerClient $partner): int
    {
        Heartbeat::ping('apiway:sync');

        if (! $partner->isConfigured()) {
            $this->warn('ProxyBR partner token not configured — nothing to do.');

            return self::SUCCESS;
        }

        $result = $apiway->syncStatuses();

        $this->info("Expired locally: {$result['expired']}, reconciled from partner: {$result['synced']}.");

        return self::SUCCESS;
    }
}
