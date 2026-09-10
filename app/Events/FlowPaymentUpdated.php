<?php

namespace App\Events;

use App\Broadcasting\Channels;
use App\Models\FlowPayment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A charge a flow issued was created, paid, or ran out.
 *
 * On the conversation's connection channel, not the tenant one: it names a
 * contact and an amount, which is conversation content, and an agent who was
 * not given this inbox must not learn who paid what in it.
 *
 * The thread itself is updated by the info note FlowPaymentService writes (a
 * normal message-received). This event is for the rest of the dashboard: a
 * toast when somebody pays, and the Integrations page refreshing its list.
 */
class FlowPaymentUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public FlowPayment $payment)
    {
        //
    }

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        $conversation = $this->payment->conversation;

        if ($conversation === null) {
            return [];
        }

        return [
            Channels::connection($this->payment->tenant_id, $conversation->connection_id),
        ];
    }

    public function broadcastWhen(): bool
    {
        return $this->payment->conversation_id !== null;
    }

    public function broadcastAs(): string
    {
        return 'flow-payment-updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $payment = $this->payment;

        return [
            'id' => $payment->id,
            'integration_id' => $payment->integration_id,
            'conversation_id' => $payment->conversation_id,
            'status' => $payment->status->value,
            'amount_cents' => $payment->amount_cents,
            'currency' => $payment->currency,
            'provider' => $payment->provider?->value,
            'method' => $payment->method,
            'contact_name' => $payment->contact?->name,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'paid_late' => (bool) ($payment->meta['paid_late'] ?? false),
        ];
    }
}
