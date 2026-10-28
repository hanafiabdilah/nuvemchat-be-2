<?php

namespace App\Events;

use App\Broadcasting\Channels;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Something about a workspace's YouTube changed: a channel was connected (the
 * OAuth popup finished in another window), disconnected, or an upload ended.
 *
 * Ids only. The page reads the rest itself — it has to anyway, since what is
 * live comes from YouTube on every request.
 */
class YoutubeUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $tenantId,
        public ?int $accountId = null,
    ) {}

    public function broadcastOn(): array
    {
        return [Channels::tenant($this->tenantId)];
    }

    public function broadcastAs(): string
    {
        return 'youtube-updated';
    }

    public function broadcastWith(): array
    {
        return ['account_id' => $this->accountId !== null ? (string) $this->accountId : null];
    }
}
