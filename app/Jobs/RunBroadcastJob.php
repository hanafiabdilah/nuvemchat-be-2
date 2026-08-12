<?php

namespace App\Jobs;

use App\Enums\Broadcast\RecipientStatus;
use App\Enums\Broadcast\Status;
use App\Events\BroadcastProgress;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Services\Broadcast\BroadcastSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The pump that drives one campaign.
 *
 * It claims a small batch, sends it, then re-dispatches itself with a delay
 * that holds the campaign's configured rate — rather than looping inside a
 * single long-running job. Three things fall out of that shape:
 *
 *  - Pause and cancel take effect within one batch, because the campaign's
 *    status is re-read every cycle. A job that looped internally would have to
 *    poll the database anyway, and a job that sent everything in one go could
 *    not be stopped at all.
 *  - A worker restart loses at most one batch, which the watchdog
 *    (broadcasts:tick) hands back by resetting stale `sending` rows.
 *  - Nothing sits in a worker slot sleeping between batches.
 *
 * `$tries = 1` is not a lack of care, it is the point: a retried batch would
 * re-send messages people have already received. Recovery is the watchdog's
 * job, and it only ever resurrects recipients that were never marked sent.
 */
class RunBroadcastJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** Generous: one batch is ≤25 sends, each a network round trip. */
    public int $timeout = 300;

    public function __construct(public int $broadcastId)
    {
        // A campaign must never queue behind (or ahead of) an agent's own reply
        // or a login OTP, so it rides its own queue with its own worker.
        $this->onQueue('broadcasts');
    }

    public function handle(BroadcastSender $sender): void
    {
        $broadcast = Broadcast::with('connection')->find($this->broadcastId);

        if (! $broadcast || ! $broadcast->status->isActive()) {
            return;
        }

        if (! $broadcast->getRelationValue('connection')) {
            $this->abortCampaign($broadcast, 'The connection this campaign sends from no longer exists');

            return;
        }

        $batch = $this->claimBatch($broadcast);

        if ($batch->isEmpty()) {
            $this->complete($broadcast);

            return;
        }

        $lastRecipient = $this->sendBatch($sender, $broadcast, $batch);

        // Re-read: a pause or cancel may have landed while the batch was in
        // flight, and the counters were just written from under this instance.
        $broadcast->refresh();

        broadcast(new BroadcastProgress($broadcast, $lastRecipient));

        if (! $broadcast->status->isActive()) {
            return;
        }

        if ($broadcast->pendingCount() === 0) {
            $this->complete($broadcast);

            return;
        }

        self::dispatch($broadcast->id)->delay(now()->addSeconds($broadcast->batchDelaySeconds()));
    }

    /**
     * Take the next batch out of the pending pool and mark it in flight, so a
     * second pump (a resume racing the watchdog, say) cannot pick up the same
     * people and message them twice.
     *
     * @return Collection<int, BroadcastRecipient>
     */
    private function claimBatch(Broadcast $broadcast): Collection
    {
        return DB::transaction(function () use ($broadcast) {
            $ids = BroadcastRecipient::where('broadcast_id', $broadcast->id)
                ->where('status', RecipientStatus::Pending)
                ->orderBy('id')
                ->limit($broadcast->batchSize())
                ->lockForUpdate()
                ->pluck('id');

            if ($ids->isEmpty()) {
                return new Collection();
            }

            BroadcastRecipient::whereIn('id', $ids)->update([
                'status' => RecipientStatus::Sending,
                'updated_at' => now(),
            ]);

            return BroadcastRecipient::with('contact')
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->get();
        });
    }

    /**
     * @param  Collection<int, BroadcastRecipient>  $batch
     * @return string|null  The last person this batch reached.
     */
    private function sendBatch(BroadcastSender $sender, Broadcast $broadcast, Collection $batch): ?string
    {
        $channel = $broadcast->getRelationValue('connection')->channel;
        $tally = [
            RecipientStatus::Sent->value => 0,
            RecipientStatus::Failed->value => 0,
            RecipientStatus::Skipped->value => 0,
        ];
        $lastRecipient = null;

        foreach ($batch as $index => $recipient) {
            // An evenly-spaced burst is itself the tell on an unofficial
            // WhatsApp client, so those channels wait a random beat between
            // sends. Blocking the worker is the intent, not a slip.
            if ($index > 0 && $channel->broadcastNeedsSendJitter()) {
                usleep(random_int(500_000, 2_500_000));
            }

            $status = $sender->send($broadcast, $recipient);
            $tally[$status->value] = ($tally[$status->value] ?? 0) + 1;
            $lastRecipient = $recipient->displayName();
        }

        // One statement so the counters and the heartbeat can never disagree,
        // and so a concurrent pump's increments are not clobbered.
        Broadcast::whereKey($broadcast->id)->update([
            'sent_count' => DB::raw('sent_count + ' . $tally[RecipientStatus::Sent->value]),
            'failed_count' => DB::raw('failed_count + ' . $tally[RecipientStatus::Failed->value]),
            'skipped_count' => DB::raw('skipped_count + ' . $tally[RecipientStatus::Skipped->value]),
            'last_tick_at' => now(),
            'updated_at' => now(),
        ]);

        return $lastRecipient;
    }

    private function complete(Broadcast $broadcast): void
    {
        $broadcast->update([
            'status' => Status::Completed,
            'finished_at' => now(),
            'last_tick_at' => now(),
        ]);

        broadcast(new BroadcastProgress($broadcast));
    }

    /**
     * Stop the campaign as a whole. Named away from the queue's own fail() —
     * that one is about this job instance, this one is about the campaign.
     */
    private function abortCampaign(Broadcast $broadcast, string $reason): void
    {
        Log::error('RunBroadcastJob: campaign aborted', [
            'broadcast_id' => $broadcast->id,
            'reason' => $reason,
        ]);

        $broadcast->update([
            'status' => Status::Failed,
            'error' => $reason,
            'finished_at' => now(),
            'last_tick_at' => now(),
        ]);

        broadcast(new BroadcastProgress($broadcast));
    }
}
