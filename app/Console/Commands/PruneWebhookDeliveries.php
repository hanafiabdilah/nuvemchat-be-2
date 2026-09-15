<?php

namespace App\Console\Commands;

use App\Models\WebhookDelivery;
use Illuminate\Console\Command;

/**
 * The delivery log exists to debug a receiver this week, not to archive every
 * lead event forever — and each row holds a full payload with customer data.
 */
class PruneWebhookDeliveries extends Command
{
    protected $signature = 'webhooks:prune {--days=30 : Keep this many days}';

    protected $description = 'Delete webhook delivery logs older than the retention window';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $deleted = 0;

        do {
            $batch = WebhookDelivery::where('created_at', '<', now()->subDays($days))->limit(1000)->delete();
            $deleted += $batch;
        } while ($batch > 0);

        $this->info("Deleted {$deleted} webhook deliveries older than {$days} days.");

        return self::SUCCESS;
    }
}
