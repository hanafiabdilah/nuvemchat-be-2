<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** What one ad cost on one day, in its ad account's currency. */
class MetaAdInsight extends Model
{
    protected $fillable = [
        'tenant_id',
        'meta_ad_account_id',
        'date',
        'ad_id',
        'ad_name',
        'campaign_id',
        'campaign_name',
        'spend_cents',
        'currency',
        'impressions',
        'clicks',
    ];

    // `date` stays a plain 'Y-m-d' string: a date cast writes a full datetime
    // on some drivers, and every reader compares it to calendar days.
}
