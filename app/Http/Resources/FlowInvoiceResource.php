<?php

namespace App\Http\Resources;

use App\Enums\Flow\FlowInvoiceStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A nota fiscal a flow asked for, for the list on an integration's page.
 *
 * The customer's document goes out masked: the list is for recognising whose
 * invoice a row is, and a CPF in the network tab of every integrations viewer
 * is a CPF somebody can copy.
 */
class FlowInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $meta = $this->meta ?? [];

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'provider' => $this->provider?->value,
            'amount_cents' => $this->amount_cents,
            'currency' => $this->currency,
            'description' => $this->description,
            'status' => $this->status->value,
            // Still with the authority when the flow stopped waiting.
            'released' => isset($meta['released_at']),
            'issued_late' => $this->status === FlowInvoiceStatus::Issued && isset($meta['released_at']),
            'number' => $this->number,
            'customer_name' => $this->customer_name,
            'customer_document' => $this->maskedDocument(),
            'conversation_id' => $this->conversation_id,
            'contact' => $this->whenLoaded('contact', fn () => $this->contact ? [
                'id' => $this->contact->id,
                'name' => $this->contact->name,
            ] : null),
            'flow' => $this->whenLoaded('flow', fn () => $this->flow ? [
                'id' => $this->flow->id,
                'name' => $this->flow->name,
            ] : null),
            // Our signed links, which work whether or not the platform's own
            // file sits behind its API key.
            'pdf_url' => $this->documentUrl('pdf'),
            'xml_url' => $this->documentUrl('xml'),
            'failure_reason' => $this->failure_reason,
            'issued_at' => $this->issued_at,
            'created_at' => $this->created_at,
        ];
    }
}
