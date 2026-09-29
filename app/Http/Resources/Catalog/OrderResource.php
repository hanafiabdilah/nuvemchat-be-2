<?php

namespace App\Http\Resources\Catalog;

use App\Services\Contact\ContactIdentity;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** An order the AI took, for the Orders page. */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $payment = $this->relationLoaded('latestPayment') ? $this->latestPayment : null;

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'currency' => $this->currency,
            'total_cents' => $this->total_cents,
            'total' => $this->formattedTotal(),
            'conversation_id' => $this->conversation_id,
            'contact' => $this->whenLoaded('contact', fn () => $this->contact ? [
                'id' => $this->contact->id,
                'name' => ContactIdentity::name($this->contact) ?? $this->contact->name,
            ] : null),
            'items_count' => $this->whenCounted('items'),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'product_variant_id' => $item->product_variant_id,
                'name' => $item->name,
                'sku' => $item->sku,
                'quantity' => $item->quantity,
                'unit_price_cents' => $item->unit_price_cents,
                'unit_price' => Money::format($item->unit_price_cents, $this->currency),
                'line_total_cents' => $item->line_total_cents,
                'line_total' => Money::format($item->line_total_cents, $this->currency),
            ])->values()),
            'payment' => $payment ? [
                'status' => $payment->status->value,
                'provider' => $payment->provider?->value,
                'method' => $payment->method,
                'reference' => $payment->reference,
                'expires_at' => $payment->expires_at?->toIso8601String(),
                'paid_at' => $payment->paid_at?->toIso8601String(),
                'failure_reason' => $payment->failure_reason,
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
        ];
    }
}
