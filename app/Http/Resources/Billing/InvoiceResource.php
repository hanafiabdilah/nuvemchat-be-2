<?php

namespace App\Http\Resources\Billing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'purpose' => $this->purpose,
            'payment_method' => $this->payment_method,
            'amount_cents' => $this->amount_cents,
            'currency' => $this->currency,
            'period_start' => $this->period_start,
            'period_end' => $this->period_end,
            'due_date' => $this->due_date,
            'paid_at' => $this->paid_at,
            // Pix charge data — only present for pix invoices.
            'pix' => $this->payment_method?->value === 'pix' ? [
                'qr_code' => $this->pix_qr_code,
                'qr_code_base64' => $this->pix_qr_code_base64,
                'copy_paste' => $this->pix_copy_paste,
                'expires_at' => $this->pix_expires_at,
            ] : null,
            // A hosted payment page (dLocal Go, direct billing outside Brazil).
            // Only while the invoice can still be paid: a link to a settled or
            // dead checkout is a button that leads nowhere.
            'checkout' => $this->payment_method?->value === 'checkout' ? [
                'url' => $this->status?->value === 'pending' ? $this->checkout_url : null,
                'expires_at' => $this->checkout_expires_at,
            ] : null,
            // A first card charge the issuer wants authenticated (3-D Secure):
            // the page sends the customer here and the webhook settles it.
            'authentication' => $this->payment_method?->value === 'card'
                && $this->status?->value === 'pending'
                && $this->checkout_url
                ? ['url' => $this->checkout_url]
                : null,
            'gateway' => $this->gateway ?? 'payment_service',
            'created_at' => $this->created_at,
        ];
    }
}
