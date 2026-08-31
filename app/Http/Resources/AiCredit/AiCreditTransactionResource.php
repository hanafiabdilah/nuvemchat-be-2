<?php

namespace App\Http\Resources\AiCredit;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of the workspace's credit statement.
 *
 * `cost_usd`, `usd_brl_rate` and `markup_pct` are deliberately NOT here. The
 * customer bought credit in reais and spends it in reais; showing them the
 * provider's wholesale price next to what they paid turns every statement into
 * an argument about the margin, and the margin is the product. The columns
 * exist for the Back Office and for reconciliation, which is where they are
 * read.
 */
class AiCreditTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'amount_cents' => $this->amount_cents,
            'balance_after_cents' => $this->balance_after_cents,
            'currency' => $this->currency,
            'description' => $this->description,
            'conversation_id' => $this->meta['conversation_id'] ?? null,
            'created_at' => $this->created_at,
        ];
    }
}
