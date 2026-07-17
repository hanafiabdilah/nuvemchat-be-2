<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Invoice as the Back Office sees it: with the owning customer and plan, and
 * without the pix QR payload.
 *
 * Deliberately not Billing\InvoiceResource — that one is tenant-facing, carries no
 * customer context, and ships pix_qr_code_base64 (a longText) on every row.
 */
class AdminInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'payment_method' => $this->payment_method,
            'amount_cents' => $this->amount_cents,
            'currency' => $this->currency,
            'period_start' => $this->period_start,
            'period_end' => $this->period_end,
            'due_date' => $this->due_date,
            'paid_at' => $this->paid_at,
            'mp_payment_id' => $this->mp_payment_id,
            'subscription_id' => $this->subscription_id,
            'tenant_id' => $this->tenant_id,
            'tenant' => $this->whenLoaded('tenant', fn () => [
                'id' => $this->tenant->id,
                'user' => $this->tenant->relationLoaded('user') && $this->tenant->user ? [
                    'name' => $this->tenant->user->name,
                    'email' => $this->tenant->user->email,
                ] : null,
            ]),
            'plan' => $this->whenLoaded('subscription', fn () => $this->subscription?->plan ? [
                'id' => $this->subscription->plan->id,
                'name' => $this->subscription->plan->name,
            ] : null),
            'created_at' => $this->created_at,
        ];
    }
}
