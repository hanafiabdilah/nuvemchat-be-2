<?php

namespace App\Models;

use App\Enums\Flow\FlowStateStatus;
use Illuminate\Database\Eloquent\Model;

class FlowState extends Model
{
    protected $fillable = [
        'conversation_id',
        'flow_id',
        'current_node_id',
        'state_data',
        'status',
        'completed_at',
    ];

    protected $casts = [
        'state_data' => 'array',
        'status' => FlowStateStatus::class,
        'completed_at' => 'datetime',
    ];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function flow()
    {
        return $this->belongsTo(Flow::class);
    }

    public function currentNode()
    {
        return $this->belongsTo(FlowNode::class, 'current_node_id');
    }
}
