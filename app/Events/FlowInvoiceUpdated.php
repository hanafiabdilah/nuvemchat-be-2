<?php

namespace App\Events;

use App\Broadcasting\Channels;
use App\Models\FlowInvoice;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A nota fiscal a flow asked for was requested, issued, rejected or cancelled.
 *
 * On the conversation's connection channel for the reason FlowPaymentUpdated
 * is: it names a contact and an amount. Never the document number with the
 * CPF, never the PDF link — the thread's info note carries what an agent needs,
 * and this event only tells open dashboards to refresh.
 */
class FlowInvoiceUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public FlowInvoice $invoice)
    {
        //
    }

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        $conversation = $this->invoice->conversation;

        if ($conversation === null) {
            return [];
        }

        return [
            Channels::connection($this->invoice->tenant_id, $conversation->connection_id),
        ];
    }

    public function broadcastWhen(): bool
    {
        return $this->invoice->conversation_id !== null;
    }

    public function broadcastAs(): string
    {
        return 'flow-invoice-updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $invoice = $this->invoice;

        return [
            'id' => $invoice->id,
            'integration_id' => $invoice->integration_id,
            'conversation_id' => $invoice->conversation_id,
            'status' => $invoice->status->value,
            'amount_cents' => $invoice->amount_cents,
            'currency' => $invoice->currency,
            'provider' => $invoice->provider?->value,
            'number' => $invoice->number,
            'contact_name' => $invoice->contact?->name,
            'issued_late' => $invoice->status === \App\Enums\Flow\FlowInvoiceStatus::Issued
                && isset($invoice->meta['released_at']),
        ];
    }
}
