<?php

namespace App\Models;

use App\Enums\Affiliate\CommissionType;
use Illuminate\Database\Eloquent\Model;

/**
 * A user who earns a share of the plans bought by the workspaces they bring
 * in. See the migration and App\Services\Affiliates\AffiliateCommissions.
 */
class Affiliate extends Model
{
    protected $fillable = [
        'user_id',
        'code',
        'is_active',
        'commission_percent',
        'commission_type',
        'recurring_months',
        'payout_details',
        'note',
        'created_by_admin_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'commission_percent' => 'integer',
        'commission_type' => CommissionType::class,
        'recurring_months' => 'integer',
        'payout_details' => 'array',
    ];

    public static function normalizeCode(?string $code): string
    {
        return strtolower(trim((string) $code));
    }

    /** The link the affiliator shares. Always on the platform host. */
    public function link(): string
    {
        return url('/r/'.$this->code);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function referrals()
    {
        return $this->hasMany(Tenant::class, 'referred_by_affiliate_id');
    }

    public function commissions()
    {
        return $this->hasMany(AffiliateCommission::class);
    }

    public function payouts()
    {
        return $this->hasMany(AffiliatePayout::class);
    }

    public function clicks()
    {
        return $this->hasMany(AffiliateClick::class);
    }
}
