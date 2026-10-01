<?php

namespace App\Console\Commands\Push;

use App\Models\DeviceToken;
use Illuminate\Console\Command;

/**
 * Drops phones that stopped opening the app. The app re-registers its token on
 * every launch, so a row that has not moved in `push.stale_after_days` belongs
 * to a phone that was wiped, sold or simply abandoned — and FCM tokens that old
 * are the ones it has already stopped honouring.
 */
class PruneDeviceTokens extends Command
{
    protected $signature = 'push:prune-devices {--dry-run}';

    protected $description = 'Delete mobile push tokens that have not re-registered in a long time';

    public function handle(): int
    {
        $cutoff = now()->subDays(max(1, (int) config('push.stale_after_days', 60)));

        $query = DeviceToken::query()->where(function ($q) use ($cutoff) {
            $q->where('last_registered_at', '<', $cutoff)
                ->orWhere(fn ($q) => $q->whereNull('last_registered_at')->where('created_at', '<', $cutoff));
        });

        $count = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->info("{$count} stale device(s) would be deleted.");

            return self::SUCCESS;
        }

        $query->delete();
        $this->info("{$count} stale device(s) deleted.");

        return self::SUCCESS;
    }
}
