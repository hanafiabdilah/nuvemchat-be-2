<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A topic whose video already opened by itself for this person. */
class TutorialTopicAutoOpen extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'topic'];
}
