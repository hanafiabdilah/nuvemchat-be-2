<?php

namespace App\Console\Commands\Billing;

use App\Jobs\RenewApiwaySubscription;
use App\Models\ApiwaySubscription;
use App\Services\Connection\Apiway\ApiwayPartnerClient;
use App\Support\Heartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Renew API Way instances at ProxyBR, for free, a few days before they expire.
 *
 * ProxyBR has NO grace period — a subscription past expires_at is revoked
 * permanently by their hourly cron, together with the number paired on it. So
 * an instance that should keep working has to be renewed before the day.
 *
 * Instances are free since Nov 2026 (see ApiwayService), so nobody is charged
 * here and there is nothing to warn about. Two rules decide which ones renew:
 *
 *  - it serves a connection. A spare one (its connection was deleted) is left
 *    to run out: the next connection made before then still picks it up, and
 *    renewing spares forever would keep paying ProxyBR for numbers nobody uses;
 *  - the workspace's plan is usable (active, grace or manual). The instance is
 *    free because the plan is paid; a lapsed plan stops it, as it always did.
 *
 * Rows from the paid era (`source = unit`) follow the same two rules — what was
 * bought then is renewed for free now, like everything else.
 */
class RenewApiwaySubscriptions extends Command
{
    protected $signature = 'apiway:renew
                            {--days-before=3 : Renew subscriptions expiring within this many days}';

    protected $description = 'Renew, for free, the API Way instances that serve a connection';

    public function handle(ApiwayPartnerClient $partner): int
    {
        Heartbeat::ping('apiway:renew');

        if (! $partner->isConfigured()) {
            $this->warn('ProxyBR partner token not configured — nothing to do.');

            return self::SUCCESS;
        }

        $rows = ApiwaySubscription::query()
            ->renewable()
            ->whereNotNull('provider_subscription_id')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays((int) $this->option('days-before')))
            ->where('expires_at', '>', now())
            ->with(['tenant.currentSubscription', 'instances:id,apiway_subscription_id,connection_id'])
            ->get();

        $renewed = 0;
        $spare = 0;
        $lapsed = 0;

        foreach ($rows as $row) {
            if (! $row->instances->contains(fn ($instance) => $instance->connection_id !== null)) {
                $spare++;

                continue;
            }

            if (! $row->tenant?->currentSubscription?->isUsable()) {
                Log::info('API Way instance not renewed: the workspace plan is not usable', [
                    'apiway_subscription_id' => $row->id,
                    'tenant_id' => $row->tenant_id,
                    'expires_at' => $row->expires_at->toIso8601String(),
                ]);
                $lapsed++;

                continue;
            }

            // One key per subscription per expiry: a run repeated on the same
            // day (or a job retried) renews once at ProxyBR, and next month's
            // renewal is a new key because the expiry it moves is a new date.
            RenewApiwaySubscription::dispatch(
                $row->id,
                'pingly-free-renew-'.$row->id.'-'.$row->expires_at->format('Ymd'),
            );
            $renewed++;
        }

        $this->info("Renewed: {$renewed}, spare (left to expire): {$spare}, plan not usable: {$lapsed}.");

        return self::SUCCESS;
    }
}
