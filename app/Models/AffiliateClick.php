<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One visitor opening an affiliate link, counted once per day. */
class AffiliateClick extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['affiliate_id', 'visitor_hash', 'day'];

    protected $casts = ['day' => 'date'];
}
