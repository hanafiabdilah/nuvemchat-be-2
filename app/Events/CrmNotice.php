<?php

namespace App\Events;

use App\Broadcasting\Channels;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Something in the CRM that one person has to know about now: a lead or deal
 * given to them, or a task reminder coming due. On that person's own channel —
 * it is about them, and nobody else's dashboard should toast it.
 */
class CrmNotice implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  string  $kind  assigned | task_reminder | automation
     * @param  array<string, mixed>  $payload  ids, a title, a link — never customer messages
     */
    public function __construct(
        public int $userId,
        public string $kind,
        public array $payload,
    ) {}

    public function broadcastOn(): array
    {
        return [Channels::user($this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'crm-notice';
    }

    public function broadcastWith(): array
    {
        return ['kind' => $this->kind] + $this->payload;
    }
}
