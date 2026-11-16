<?php

namespace App\Models;

use App\Enums\Billing\CouponDuration;
use App\Enums\Billing\CouponScope;
use App\Enums\Billing\InvoicePurpose;
use App\Enums\Billing\InvoiceStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * A percentage off a plan's charges, or a bonus on a balance top-up.
 *
 * Usage is never stored here: it is read off the invoices that carry the
 * coupon (see the migration and App\Services\Billing\Coupons).
 */
class Coupon extends Model
{
    protected $fillable = [
        'code',
        'name',
        'scope',
        'percent_off',
        'duration',
        'duration_cycles',
        'applies_to_addons',
        'is_public',
        'is_active',
        'starts_at',
        'ends_at',
        'max_redemptions',
        'plan_ids',
        'billing_cycles',
        'market_codes',
        'created_by_admin_id',
    ];

    protected $casts = [
        'scope' => CouponScope::class,
        'duration' => CouponDuration::class,
        'percent_off' => 'integer',
        'duration_cycles' => 'integer',
        'applies_to_addons' => 'boolean',
        'is_public' => 'boolean',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'max_redemptions' => 'integer',
        'plan_ids' => 'array',
        'billing_cycles' => 'array',
        'market_codes' => 'array',
    ];

    /**
     * The fields a running subscription was promised. Locked once any charge
     * has carried the coupon, so the Back Office never shows a percentage that
     * is not the one being charged.
     */
    public const LOCKED_ONCE_USED = [
        'code',
        'scope',
        'percent_off',
        'duration',
        'duration_cycles',
        'applies_to_addons',
    ];

    public static function normalizeCode(?string $code): string
    {
        return strtoupper(trim((string) $code));
    }

    /** Cycles a plan coupon discounts, or null for no end. A top-up coupon is one. */
    public function cycles(): ?int
    {
        if ($this->scope === CouponScope::Topup) {
            return 1;
        }

        return $this->duration->cycles($this->duration_cycles);
    }

    /**
     * What a subscription keeps of this coupon: everything a later charge
     * needs to price itself without reading the coupon row again.
     *
     * @return array<string, mixed>
     */
    public function termsSnapshot(int $cyclesUsedBefore = 0): array
    {
        return [
            'coupon_id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'percent_off' => $this->percent_off,
            'duration' => $this->duration->value,
            'cycles' => $this->cycles(),
            'applies_to_addons' => $this->applies_to_addons,
            'plan_ids' => $this->plan_ids ?: null,
            'billing_cycles' => $this->billing_cycles ?: null,
            // Cycles a coupon carried over from a replaced plan already used.
            'cycles_used_before' => $cyclesUsedBefore,
        ];
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    /** Whether any charge has ever carried this coupon (paid or still open). */
    public function hasBeenUsed(): bool
    {
        return $this->invoices()
            ->whereIn('status', [InvoiceStatus::Paid->value, InvoiceStatus::Pending->value, InvoiceStatus::Refunded->value])
            ->exists();
    }

    /** The purpose of the invoices this coupon can sit on. */
    public function invoicePurpose(): InvoicePurpose
    {
        return $this->scope === CouponScope::Topup ? InvoicePurpose::CreditTopup : InvoicePurpose::Subscription;
    }
}
