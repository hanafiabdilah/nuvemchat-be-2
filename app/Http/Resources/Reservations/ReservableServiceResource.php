<?php

namespace App\Http\Resources\Reservations;

use App\Services\Money\MarketMoney;
use App\Services\Reservations\ReservationSchedule;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A bookable service, for the Reservations page and the flow builder's picker. */
class ReservableServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $currency = $this->tenant?->currency() ?? MarketMoney::baseCurrency();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'active' => (bool) $this->active,
            'position' => (int) $this->position,
            'duration_minutes' => (int) $this->duration_minutes,
            'slot_interval_minutes' => $this->slot_interval_minutes,
            'capacity' => (int) $this->capacity,
            'capacity_unit' => $this->capacity_unit?->value ?? 'bookings',
            'max_party_size' => $this->max_party_size,
            'weekly_hours' => ReservationSchedule::normalizeWeekly($this->weekly_hours),
            'date_overrides' => ReservationSchedule::normalizeOverrides($this->date_overrides),
            'min_notice_minutes' => (int) $this->min_notice_minutes,
            'max_days_ahead' => (int) $this->max_days_ahead,
            'auto_confirm' => (bool) $this->auto_confirm,
            'cancel_until_hours' => $this->cancel_until_hours,
            'payment_mode' => $this->payment_mode?->value ?? 'none',
            'price_cents' => $this->price_cents,
            'deposit_cents' => $this->deposit_cents,
            'currency' => $currency,
            'price_label' => $this->price_cents ? Money::format($this->price_cents, $currency) : null,
            'upcoming_count' => $this->whenCounted('upcomingReservations'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
