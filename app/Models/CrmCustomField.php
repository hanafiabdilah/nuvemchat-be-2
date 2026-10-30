<?php

namespace App\Models;

use App\Enums\Crm\CustomFieldType;
use Illuminate\Database\Eloquent\Model;

/** A field the workspace added to its customers or companies. */
class CrmCustomField extends Model
{
    public const ENTITIES = ['customer', 'company'];

    protected $fillable = ['tenant_id', 'entity', 'key', 'label', 'type', 'options', 'position'];

    protected $casts = [
        'type' => CustomFieldType::class,
        'options' => 'array',
        'position' => 'integer',
    ];
}
