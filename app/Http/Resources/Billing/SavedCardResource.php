<?php

namespace App\Http\Resources\Billing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the page shows and what the card fields need. `card_id` is the gateway's
 * id for the card: the browser needs it to mint a token (with the CVV typed
 * again), and it charges nothing on its own. The customer id stays here.
 */
class SavedCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'card_id' => $this->card_id,
            'brand' => $this->brand,
            'payment_type' => $this->payment_type,
            'last_four' => $this->last_four,
            'exp_month' => $this->exp_month,
            'exp_year' => $this->exp_year,
            'holder_name' => $this->holder_name,
            'expired' => $this->isExpired(),
            'last_used_at' => $this->last_used_at,
            'created_at' => $this->created_at,
        ];
    }
}
