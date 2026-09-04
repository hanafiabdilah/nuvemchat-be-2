<?php

namespace App\Events;

use App\Broadcasting\Channels;
use App\Models\VirtualNumberMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An SMS landed on a rented number.
 *
 * `ShouldBroadcastNow`, not the queue: somebody is looking at the screen with a
 * verification form open in another tab, and a code that arrives after the app
 * has stopped accepting it is the same as no code at all.
 *
 * The code travels in the payload. The tenant channel is private and scoped to
 * the workspace that rented the number, and withholding it here would only mean
 * the page has to fetch what it was just told about.
 */
class VirtualNumberSmsReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public VirtualNumberMessage $message,
    ) {}

    /** @return array<int, \Illuminate\Broadcasting\Channel> */
    public function broadcastOn(): array
    {
        return [Channels::tenant($this->message->tenant_id)];
    }

    public function broadcastAs(): string
    {
        return 'virtual-number-sms';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'number_id' => $this->message->virtual_number_id,
            'msisdn' => $this->message->number?->msisdn,
            'sender' => $this->message->sender,
            'body' => $this->message->body,
            'code' => $this->message->code,
            'received_at' => $this->message->received_at?->toISOString(),
        ];
    }
}
