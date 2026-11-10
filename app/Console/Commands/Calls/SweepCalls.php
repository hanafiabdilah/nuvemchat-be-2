<?php

namespace App\Console\Commands\Calls;

use App\Services\Calls\CallService;
use App\Support\Heartbeat;
use Illuminate\Console\Command;

/**
 * Closes the calls nobody else will.
 *
 * Ringing calls whose `terminate` webhook was lost, claims whose browser never
 * finished answering, and running calls whose browser stopped reporting in (a
 * closed tab, a crashed app). Without this an agent stays "on a call" forever:
 * shown to colleagues as busy and never rung again.
 */
class SweepCalls extends Command
{
    protected $signature = 'calls:sweep';

    protected $description = 'End WhatsApp calls whose webhook or answering browser went silent';

    public function handle(CallService $calls): int
    {
        Heartbeat::ping('calls:sweep');

        $counts = $calls->sweep();

        $this->info(sprintf(
            'Expired %d ringing, failed %d connecting, ended %d lost call(s).',
            $counts['expired'],
            $counts['failed'],
            $counts['lost'],
        ));

        return self::SUCCESS;
    }
}
