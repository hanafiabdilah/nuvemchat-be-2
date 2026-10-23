<?php

namespace App\Console\Commands\Connection;

use App\Enums\Connection\Channel;
use App\Models\ApiwayQueuedSend;
use App\Models\Connection;
use App\Services\Message\ApiwaySendQueue;
use App\Support\Heartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Follows the WhatsApp messages API Way is holding in its anti-ban queue.
 *
 * One send-log read per connection that has something waiting, skipping the
 * messages the core already scheduled for later (a daily-cap hold waits until
 * 00:00 UTC; there is nothing to learn about it before then). See
 * App\Services\Message\ApiwaySendQueue.
 *
 * Without it a held message keeps saying "in API Way's queue" after it went
 * out — or after it failed.
 */
class SyncApiwaySendQueue extends Command
{
    protected $signature = 'apiway:sync-send-queue {--connections=50 : Most connections read per pass}';

    protected $description = 'Settle WhatsApp messages API Way held in its anti-ban queue: sent, failed or unconfirmed';

    public function handle(ApiwaySendQueue $queue): int
    {
        Heartbeat::ping('apiway:sync-send-queue');

        $due = ApiwayQueuedSend::query()
            ->where(fn ($query) => $query->whereNull('check_after')->orWhere('check_after', '<=', now()))
            ->orderBy('id')
            ->with('message')
            ->get()
            ->groupBy('connection_id')
            ->take(max(1, (int) $this->option('connections')));

        $settled = 0;
        $waiting = 0;

        foreach ($due as $connectionId => $rows) {
            $channelConnection = Connection::find($connectionId);

            if (! $channelConnection || $channelConnection->channel !== Channel::WhatsappApiway) {
                ApiwayQueuedSend::query()->whereIn('id', $rows->pluck('id'))->delete();

                continue;
            }

            try {
                $stats = $queue->sync($channelConnection, $rows);
                $settled += $stats['settled'];
                $waiting += $stats['waiting'];
            } catch (\Throwable $e) {
                Log::warning('apiway:sync-send-queue failed for a connection', [
                    'connection_id' => $connectionId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($settled > 0 || $waiting > 0) {
            $this->info("Settled {$settled}, still waiting {$waiting}.");
        }

        return self::SUCCESS;
    }
}
