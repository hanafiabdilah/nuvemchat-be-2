<?php

namespace App\Console\Commands\Connection;

use App\Enums\Connection\Channel;
use App\Models\Connection;
use App\Services\Connection\Channels\WhatsappApiwayChannel;
use App\Support\Heartbeat;
use Illuminate\Console\Command;

/**
 * Keeps every linked API Way connection's status in step with its session.
 *
 * The core tells us when a session changes (SyncApiwayConnectionStatus reacts
 * to those events), but an event can be lost, and a number unlinked from the
 * phone used to stay "connected" on the Connections page until somebody
 * pressed "check status". This pass is the guarantee behind the events: at
 * worst, a disconnection shows up a couple of minutes late.
 *
 * Only a definitive answer from the core moves a status — see
 * WhatsappApiwayChannel::syncStatus().
 */
class SyncApiwayConnectionStatuses extends Command
{
    protected $signature = 'apiway:sync-connection-status {--connection= : Only this connection id}';

    protected $description = 'Read each linked API Way instance\'s session status and update its connection';

    public function handle(): int
    {
        Heartbeat::ping('apiway:sync-connection-status');

        $channel = new WhatsappApiwayChannel;
        $changed = 0;
        $unanswered = 0;

        Connection::where('channel', Channel::WhatsappApiway)
            ->when($this->option('connection'), fn ($query, $id) => $query->where('id', $id))
            ->orderBy('id')
            ->get()
            ->filter(fn (Connection $connection) => ! empty($connection->credentials['instance_id']) && ! empty($connection->credentials['token']))
            ->each(function (Connection $connection) use ($channel, &$changed, &$unanswered) {
                $result = $channel->syncStatus($connection);

                if ($result === true) {
                    $changed++;
                    $this->line("  #{$connection->id} → {$connection->status->value}");
                } elseif ($result === null) {
                    $unanswered++;
                }
            });

        $this->info("Status changed on {$changed} connection(s); {$unanswered} without an answer from the core.");

        return self::SUCCESS;
    }
}
