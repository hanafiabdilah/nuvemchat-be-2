<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FlowState extends Model
{
    protected $fillable = [
        'conversation_id',
        'flow_id',
        'current_node_id',
        'state_data',
    ];

    protected $casts = [
        'state_data' => 'array',
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
