<?php

namespace App\Events;

use App\Broadcasting\Channels;
use App\Models\Reservation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Something about a reservation needs an operator: a new request to confirm,
 * one still waiting after the reminder delay, a customer who called theirs
 * off, or money that arrived for a slot already released. The dashboard shows
 * a toast to whoever may confirm reservations.
 *
 * On the conversation's content channels (Channels::forConversationContent),
 * so connection access and exclusive threads decide who hears it — the
 * payload names the customer.
 */
class ReservationAttention implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public const KIND_REQUESTED = 'requested';

    public const KIND_REMINDER = 'reminder';

    public const KIND_CANCELLED = 'cancelled';

    public const KIND_PAID_LATE = 'paid_late';

    public function __construct(public Reservation $reservation, public string $kind) {}

    /** @return array<int, \Illuminate\Broadcasting\Channel> */
    public function broadcastOn(): array
    {
        $conversation = $this->reservation->conversation;

        return $conversation ? Channels::forConversationContent($conversation) : [];
    }

    public function broadcastAs(): string
    {
        return 'reservation-attention';
    }

    public function broadcastWith(): array
    {
        $reservation = $this->reservation;
        $start = $reservation->localStart($reservation->timezone());

        return [
            'kind' => $this->kind,
            'reservation_id' => $reservation->id,
            'conversation_id' => $reservation->conversation_id,
            'contact_name' => $reservation->contact?->name ?? $reservation->customer_name,
            'service_name' => $reservation->service_name,
            'date' => $start->format('Y-m-d'),
            'time' => $start->format('H:i'),
            'party_size' => (int) $reservation->party_size,
        ];
    }
}
