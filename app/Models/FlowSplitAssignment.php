<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One conversation dealt to one variant of a split test. See the migration and
 * App\Services\Flow\SplitTests.
 */
class FlowSplitAssignment extends Model
{
    protected $fillable = [
        'tenant_id',
        'flow_id',
        'flow_node_id',
        'variant_id',
        'conversation_id',
        'contact_id',
        'last_message_id',
    ];

    protected $casts = [
        'last_message_id' => 'integer',
    ];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }
}
