<?php

namespace App\Http\Resources\Reservations;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One reservation, for the calendar and the conversation's reservation card.
 * Times go out as ISO-8601 in UTC and also as the workspace's own date and
 * time — both draw in the business's clock, whatever zone the browser is in.
 */
class ReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $timezone = $this->timezone();
        $start = $this->localStart($timezone);
        $end = $this->localEnd($timezone);
        $offered = $this->localOffered($timezone);
        $payment = $this->relationLoaded('latestPayment') ? $this->latestPayment : null;

        return [
            'id' => $this->id,
            'code' => $this->code,
            'status' => $this->status->value,
            'source' => $this->source->value,
            'service_id' => $this->reservable_service_id,
            'service_name' => $this->service_name,
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            'date' => $start->format('Y-m-d'),
            'time' => $start->format('H:i'),
            'end_time' => $end->format('H:i'),
            'timezone' => $timezone,
            'party_size' => (int) $this->party_size,
            'offered' => $offered ? [
                'starts_at' => $this->offered_starts_at->toIso8601String(),
                'date' => $offered->format('Y-m-d'),
                'time' => $offered->format('H:i'),
            ] : null,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'customer_email' => $this->customer_email,
            'notes' => $this->notes,
            'review_reason' => $this->review_reason?->value,
            'review_note' => $this->review_note,
            'decided_by' => $this->whenLoaded('decidedBy', fn () => $this->decidedBy ? ['id' => $this->decidedBy->id, 'name' => $this->decidedBy->name] : null, null),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_note' => $this->decision_note,
            'notify_status' => $this->notify_status,
            'notify_reason' => $this->notify_reason,
            'contact_id' => $this->contact_id,
            'conversation_id' => $this->conversation_id,
            'amount_due_cents' => (int) $this->amount_due_cents,
            'amount_due' => $this->amount_due_cents > 0 ? $this->formattedAmountDue() : null,
            'currency' => $this->currency,
            'paid' => $this->paid_at !== null,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'hold_expires_at' => $this->hold_expires_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancelled_by' => $this->cancelled_by,
            'cancellation_reason' => $this->cancellation_reason,
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy ? ['id' => $this->createdBy->id, 'name' => $this->createdBy->name] : null, null),
            'payment' => $payment ? [
                'status' => $payment->status->value,
                'provider' => $payment->provider?->value,
                'reference' => $payment->reference,
                'amount' => $payment->formattedAmount(),
                'payment_url' => $payment->payment_url,
                'expires_at' => $payment->expires_at?->toIso8601String(),
                'paid_at' => $payment->paid_at?->toIso8601String(),
                'failure_reason' => $payment->failure_reason,
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
