<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A read or starred mark on one platform comment (see the migration). */
class SocialCommentMark extends Model
{
    protected $fillable = [
        'tenant_id',
        'platform',
        'account_id',
        'comment_id',
        'read_at',
        'read_by',
        'starred_at',
        'starred_by',
    ];

    protected $casts = [
        'read_at' => 'datetime',
        'starred_at' => 'datetime',
    ];
}
