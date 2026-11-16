<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Money an admin paid an affiliator, outside the system, and recorded here. */
class AffiliatePayout extends Model
{
    protected $fillable = [
        'affiliate_id',
        'amount_cents',
        'currency',
        'method',
        'reference',
        'note',
        'paid_at',
        'admin_id',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'paid_at' => 'datetime',
    ];

    public function affiliate()
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function commissions()
    {
        return $this->hasMany(AffiliateCommission::class, 'payout_id');
    }

    public function admin()
    {
        return $this->belongsTo(Admin::class);
    }
}
