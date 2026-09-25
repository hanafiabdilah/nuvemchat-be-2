<?php

namespace App\Services\Broadcast;

use App\Enums\Broadcast\RecipientStatus;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Message;
use Illuminate\Support\Facades\DB;

/**
 * What a campaign learns after its sends are already counted.
 *
 * BroadcastSender decides a recipient's fate from whether the send call threw,
 * which is the only thing it can know at the time. On WhatsApp that is not the
 * whole story: Meta answers 200 to accept a message and only tries to deliver
 * it afterwards, so a campaign can finish "4 sent, 0 failed" while four people
 * received nothing. The status webhook is where that turns up, minutes later —
 * this is how it gets back into the campaign's own tally.
 */
class BroadcastDelivery
{
    /**
     * Record that the channel refused to deliver a campaign message.
     *
     * A no-op for anything that is not a campaign send, or that the campaign
     * already counted as failed — the webhook can arrive more than once.
     */
    public static function markFailed(Message $message, string $reason): void
    {
        $recipient = BroadcastRecipient::where('message_id', $message->id)
            ->where('status', RecipientStatus::Sent)
            ->first();

        if (! $recipient) {
            return;
        }

        DB::transaction(function () use ($recipient, $reason) {
            // Re-read under the lock: two failures from the same batch land
            // here at once, and both would otherwise move the same counter
            // from the same starting number.
            $fresh = BroadcastRecipient::whereKey($recipient->id)->lockForUpdate()->first();

            if (! $fresh || $fresh->status !== RecipientStatus::Sent) {
                return;
            }

            $fresh->forceFill([
                'status' => RecipientStatus::Failed,
                'error' => mb_substr($reason, 0, 2000),
                // `sent_at` is deliberately left alone: we did hand this one
                // over, and when is a fact the report should keep. The status
                // is what says how it ended.
            ])->save();

            $broadcast = Broadcast::whereKey($fresh->broadcast_id)->lockForUpdate()->first();

            if (! $broadcast) {
                return;
            }

            $broadcast->forceFill([
                'sent_count' => max(0, $broadcast->sent_count - 1),
                'failed_count' => $broadcast->failed_count + 1,
            ])->save();
        });
    }
}
