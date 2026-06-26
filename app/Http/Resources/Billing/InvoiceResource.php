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
            'created_at' => $this->created_at,
        ];
    }
}
