<?php

namespace App\Events;

use App\Broadcasting\Channels;
use App\Models\Reservation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A reservation changed — so an open calendar redraws and a conversation's
 * reservation card reloads it.
 *
 * On the tenant channel, ids and status only: the calendar and the card fetch
 * the details behind their own permission, and the inbox marker travels with
 * the conversation (ReservationService::changed()), on the channels that
 * already know who may see it.
 */
class ReservationUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Reservation $reservation) {}

    /** @return array<int, \Illuminate\Broadcasting\Channel> */
    public function broadcastOn(): array
    {
        return [Channels::tenant($this->reservation->tenant_id)];
    }

    public function broadcastAs(): string
    {
        return 'reservation-updated';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->reservation->id,
            'status' => $this->reservation->status->value,
            'contact_id' => $this->reservation->contact_id,
            'conversation_id' => $this->reservation->conversation_id,
        ];
    }
}
