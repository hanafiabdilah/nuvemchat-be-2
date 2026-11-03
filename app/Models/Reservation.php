<?php

namespace App\Models;

use App\Enums\Reservation\ReservationSource;
use App\Enums\Reservation\ReservationStatus;
use App\Enums\Reservation\ReviewReason;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One booking on the calendar — taken by the AI in a conversation, or written
 * down by the team. See App\Services\Reservations\ReservationService.
 */
class Reservation extends Model
{
    /** Whether the customer has been told what the team decided. */
    public const NOTIFY_QUEUED = 'queued';

    public const NOTIFY_SENT = 'sent';

    /** The AI could not tell them; an operator has to (see `notify_reason`). */
    public const NOTIFY_OPERATOR = 'operator';

    protected $fillable = [
        'tenant_id',
        'reservable_service_id',
        'service_name',
        'code',
        'status',
        'source',
        'starts_at',
        'ends_at',
        'offered_starts_at',
        'party_size',
        'customer_name',
        'customer_phone',
        'customer_email',
        'notes',
        'review_reason',
        'review_note',
        'contact_id',
        'conversation_id',
        'flow_id',
        'flow_node_id',
        'created_by_user_id',
        'decided_by_user_id',
        'decided_at',
        'decision_note',
        'notify_status',
        'notify_reason',
        'reminded_at',
        'amount_due_cents',
        'currency',
        'paid_at',
        'hold_expires_at',
        'confirmed_at',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected $casts = [
        'status' => ReservationStatus::class,
        'source' => ReservationSource::class,
        'review_reason' => ReviewReason::class,
        'starts_at' => 'immutable_datetime',
        'ends_at' => 'immutable_datetime',
        'offered_starts_at' => 'immutable_datetime',
        'party_size' => 'integer',
        'amount_due_cents' => 'integer',
        'decided_at' => 'datetime',
        'reminded_at' => 'datetime',
        'paid_at' => 'datetime',
        'hold_expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(ReservableService::class, 'reservable_service_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(FlowPayment::class)->orderByDesc('id');
    }

    /** The charge that matters now: the newest one. */
    public function latestPayment(): HasOne
    {
        return $this->hasOne(FlowPayment::class)->latestOfMany();
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /** In play (pending, offered, awaiting payment or confirmed) and still ahead. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ReservationStatus::openValues())
            ->where('starts_at', '>', now());
    }

    /**
     * Rows that take a place on the calendar: everything but what was turned
     * down or called off — and an unpaid booking only while its charge still
     * runs.
     */
    public function scopeHoldingCapacity(Builder $query): Builder
    {
        return $query->whereIn('status', ReservationStatus::holdingValues())
            ->where(fn (Builder $q) => $q
                ->where('status', '!=', ReservationStatus::AwaitingPayment->value)
                ->orWhereNull('hold_expires_at')
                ->orWhere('hold_expires_at', '>', now()));
    }

    /** Rows whose time overlaps [start, end). */
    public function scopeOverlapping(Builder $query, CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return $query->where('starts_at', '<', $end)->where('ends_at', '>', $start);
    }

    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }

    /** Asks for money that has not arrived. */
    public function owesPayment(): bool
    {
        return $this->amount_due_cents > 0 && ! $this->isPaid();
    }

    public function formattedAmountDue(): string
    {
        return Money::format($this->amount_due_cents, $this->currency);
    }

    /** The workspace's own zone — every date a person reads is in it. */
    public function timezone(): string
    {
        return $this->tenant?->displayTimezone() ?? config('app.timezone', 'UTC');
    }

    /** The start in the workspace's own clock. */
    public function localStart(string $timezone): CarbonImmutable
    {
        return $this->starts_at->setTimezone($timezone);
    }

    public function localEnd(string $timezone): CarbonImmutable
    {
        return $this->ends_at->setTimezone($timezone);
    }

    public function localOffered(string $timezone): ?CarbonImmutable
    {
        return $this->offered_starts_at?->setTimezone($timezone);
    }

    public function duration(): int
    {
        return max(1, (int) round(($this->ends_at->getTimestamp() - $this->starts_at->getTimestamp()) / 60));
    }

    /**
     * The inbox marker: the reservation an operator most needs to see for a
     * contact — one waiting on the team first, then one waiting on the
     * customer (an offered time, an unpaid charge), then the next confirmed.
     *
     * @param  iterable<Reservation>  $open  already filtered to open ones
     */
    public static function markerOf(iterable $open): ?self
    {
        $best = null;
        $rank = fn (self $r) => match ($r->status) {
            ReservationStatus::Pending => 0,
            ReservationStatus::Offered => 1,
            ReservationStatus::AwaitingPayment => 2,
            default => 3,
        };

        foreach ($open as $reservation) {
            if (! $reservation->status->isOpen() || $reservation->starts_at->isPast()) {
                continue;
            }

            if ($best === null
                || $rank($reservation) < $rank($best)
                || ($rank($reservation) === $rank($best) && $reservation->starts_at->lessThan($best->starts_at))) {
                $best = $reservation;
            }
        }

        return $best;
    }
}
