<?php

namespace App\Models;

use App\Enums\Reservation\CapacityUnit;
use App\Enums\Reservation\ReservationPaymentMode;
use App\Enums\Reservation\ReservationStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something a customer can book — a table, a haircut, a court: when it is
 * open, how long one booking lasts, how many fit at once, the customer's
 * booking rules, whether a booking is confirmed by itself, and what it asks
 * for before it is.
 *
 * See App\Services\Reservations\ReservationAvailability for how free times are
 * worked out from it.
 */
class ReservableService extends Model
{
    /** What a service counting bookings takes per booking without its own limit. */
    public const DEFAULT_MAX_PARTY_SIZE = 50;

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'active',
        'position',
        'duration_minutes',
        'slot_interval_minutes',
        'capacity',
        'capacity_unit',
        'max_party_size',
        'weekly_hours',
        'date_overrides',
        'min_notice_minutes',
        'max_days_ahead',
        'auto_confirm',
        'cancel_until_hours',
        'payment_mode',
        'price_cents',
        'deposit_cents',
    ];

    protected $casts = [
        'active' => 'boolean',
        'position' => 'integer',
        'duration_minutes' => 'integer',
        'slot_interval_minutes' => 'integer',
        'capacity' => 'integer',
        'capacity_unit' => CapacityUnit::class,
        'max_party_size' => 'integer',
        'weekly_hours' => 'array',
        'date_overrides' => 'array',
        'min_notice_minutes' => 'integer',
        'max_days_ahead' => 'integer',
        'auto_confirm' => 'boolean',
        'cancel_until_hours' => 'integer',
        'payment_mode' => ReservationPaymentMode::class,
        'price_cents' => 'integer',
        'deposit_cents' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    /** Bookings still ahead and in play — what the services list counts. */
    public function upcomingReservations(): HasMany
    {
        return $this->hasMany(Reservation::class)
            ->where('starts_at', '>', now())
            ->whereIn('status', ReservationStatus::openValues());
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /** Minutes between one offered start time and the next. */
    public function interval(): int
    {
        return max(5, (int) ($this->slot_interval_minutes ?: $this->duration_minutes));
    }

    public function countsPeople(): bool
    {
        return $this->capacity_unit === CapacityUnit::People;
    }

    /** The biggest party one booking may bring. */
    public function maxPartySize(): int
    {
        if (! $this->countsPeople()) {
            return max(1, (int) ($this->max_party_size ?: self::DEFAULT_MAX_PARTY_SIZE));
        }

        return max(1, min((int) ($this->max_party_size ?: $this->capacity), (int) $this->capacity));
    }

    /**
     * What a booking of this size has to pay before it is confirmed — 0 when
     * nothing. Per person when the capacity counts people, which is how a
     * restaurant prices "R$ 50 per person".
     */
    public function amountDueFor(int $partySize): int
    {
        $unit = match ($this->payment_mode) {
            ReservationPaymentMode::Deposit => (int) $this->deposit_cents,
            ReservationPaymentMode::Full => (int) $this->price_cents,
            default => 0,
        };

        if ($unit <= 0) {
            return 0;
        }

        return $this->countsPeople() ? $unit * max(1, $partySize) : $unit;
    }

    public function asksForPayment(): bool
    {
        return $this->amountDueFor(1) > 0;
    }

    public function formattedPrice(string $currency): ?string
    {
        return $this->price_cents !== null && $this->price_cents > 0 ? Money::format($this->price_cents, $currency) : null;
    }
}
