<?php

namespace App\Events;

use App\Broadcasting\Channels;
use App\Models\Broadcast;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A campaign's progress moved.
 *
 * Emitted once per batch rather than once per recipient: a 5.000-person
 * campaign would otherwise put 5.000 frames on the wire to animate a bar that
 * only has a hundred or so visible positions. State changes (started, paused,
 * finished) always emit, so the dashboard never sits on a stale status waiting
 * for the next batch.
 *
 * Note this class is about *campaign* progress and has nothing to do with
 * Laravel's broadcasting subsystem beyond travelling on it.
 */
class BroadcastProgress implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Broadcast $broadcast,
        /** Who the last send in this batch went to, for a live "sending to…" line. */
        public ?string $lastRecipient = null,
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            Channels::tenant($this->broadcast->tenant_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'broadcast-progress';
    }

    public function broadcastWith(): array
    {
        $sent = (int) $this->broadcast->sent_count;
        $failed = (int) $this->broadcast->failed_count;
        $skipped = (int) $this->broadcast->skipped_count;
        $total = (int) $this->broadcast->total_recipients;

        return [
            'id' => $this->broadcast->id,
            'status' => $this->broadcast->status->value,
            'total' => $total,
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => $skipped,
            // Derived rather than counted: the pump updates this event's source
            // row in the same transaction as the counters, so subtracting is
            // both cheaper and more consistent than a second query.
            'pending' => max(0, $total - $sent - $failed - $skipped),
            'last_recipient' => $this->lastRecipient,
            'started_at' => $this->broadcast->started_at?->toIso8601String(),
            'finished_at' => $this->broadcast->finished_at?->toIso8601String(),
            'error' => $this->broadcast->error,
        ];
    }
}
