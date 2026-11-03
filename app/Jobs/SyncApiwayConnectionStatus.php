<?php

namespace App\Jobs;

use App\Enums\Connection\Channel;
use App\Models\Connection;
use App\Services\Connection\Channels\WhatsappApiwayChannel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Re-read an API Way connection's status after the core told us something
 * happened to its session — logged out from the phone, back online, paired.
 *
 * The event only says that something changed; the status endpoint says what
 * the session is now, so that is what the connection is set from. Queued
 * rather than run inside the webhook, and a little later for a plain
 * disconnect: whatsmeow reconnects by itself, and the check is worth more once
 * it has had the chance to.
 */
class SyncApiwayConnectionStatus implements ShouldQueue
{
    use Queueable;

    /** A check that fails is simply the next scheduled pass's job. */
    public int $tries = 1;

    public int $timeout = 60;

    /** Seconds to wait before checking, by how final the event sounds. */
    public const DELAY_FINAL = 3;

    public const DELAY_TRANSIENT = 60;

    public function __construct(public int $connectionId) {}

    /**
     * Queue one check per connection and delay; a burst of events (logout,
     * then disconnect, then a stream error) asks for the same answer.
     */
    public static function dispatchFor(Connection $connection, int $delaySeconds): void
    {
        if (! Cache::add("apiway-status-sync:{$connection->id}:{$delaySeconds}", true, $delaySeconds + 5)) {
            return;
        }

        self::dispatch($connection->id)->delay(now()->addSeconds($delaySeconds));
    }

    public function handle(): void
    {
        $connection = Connection::find($this->connectionId);

        if (! $connection || $connection->channel !== Channel::WhatsappApiway) {
            return;
        }

        (new WhatsappApiwayChannel)->syncStatus($connection);
    }
}
