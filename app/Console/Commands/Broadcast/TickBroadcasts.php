<?php

namespace App\Console\Commands\Broadcast;

use App\Enums\Broadcast\RecipientStatus;
use App\Enums\Broadcast\Status;
use App\Jobs\RunBroadcastJob;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Services\Broadcast\BroadcastService;
use Illuminate\Console\Command;

/**
 * The minute hand for campaigns: starts the ones that are due, and puts back on
 * their feet the ones whose pump died.
 *
 * The recovery half exists because the pump is a self-re-dispatching job with
 * no retries. That is the right trade — a retried batch would re-send messages
 * — but it means a worker killed mid-batch takes the whole campaign down with
 * it, silently, with rows stuck in `sending` forever. This is what notices.
 */
class TickBroadcasts extends Command
{
    protected $signature = 'broadcasts:tick';

    protected $description = 'Start due campaigns and revive ones whose pump stopped';

    /** A batch is ≤25 sends; anything still `sending` after this lost its worker. */
    private const STALE_CLAIM_MINUTES = 5;

    /** A running campaign ticks every batch, so silence this long means nobody is driving. */
    private const STALE_PUMP_MINUTES = 2;

    public function handle(BroadcastService $broadcasts): int
    {
        $this->startDue($broadcasts);
        $this->releaseStaleClaims();
        $this->revivePumps();

        return self::SUCCESS;
    }

    private function startDue(BroadcastService $broadcasts): void
    {
        Broadcast::where('status', Status::Scheduled)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->get()
            ->each(function (Broadcast $broadcast) use ($broadcasts) {
                try {
                    $broadcasts->start($broadcast);
                    $this->info("Started scheduled campaign #{$broadcast->id}");
                } catch (\Throwable $th) {
                    // A campaign whose recipients all vanished (or whose window
                    // closed) must not make the scheduler noisy every minute.
                    $broadcast->update([
                        'status' => Status::Failed,
                        'error' => $th->getMessage(),
                        'finished_at' => now(),
                    ]);
                    $this->warn("Scheduled campaign #{$broadcast->id} could not start: {$th->getMessage()}");
                }
            });
    }

    /**
     * Recipients a dead worker left claimed. Only ever rows that were never
     * marked sent, so this can never cause a duplicate message.
     */
    private function releaseStaleClaims(): void
    {
        $released = BroadcastRecipient::where('status', RecipientStatus::Sending)
            ->where('updated_at', '<', now()->subMinutes(self::STALE_CLAIM_MINUTES))
            ->update([
                'status' => RecipientStatus::Pending,
                'updated_at' => now(),
            ]);

        if ($released > 0) {
            $this->info("Released {$released} stale recipient claim(s)");
        }
    }

    /** Campaigns that still say `running` but stopped ticking. */
    private function revivePumps(): void
    {
        Broadcast::where('status', Status::Running)
            ->where(function ($query) {
                $query->whereNull('last_tick_at')
                    ->orWhere('last_tick_at', '<', now()->subMinutes(self::STALE_PUMP_MINUTES));
            })
            ->whereHas('recipients', fn ($query) => $query->where('status', RecipientStatus::Pending))
            ->get()
            ->each(function (Broadcast $broadcast) {
                // Stamp before dispatching so a slow queue does not get this
                // campaign dispatched again on the next tick.
                $broadcast->update(['last_tick_at' => now()]);
                RunBroadcastJob::dispatch($broadcast->id);
                $this->info("Revived stalled campaign #{$broadcast->id}");
            });
    }
}
