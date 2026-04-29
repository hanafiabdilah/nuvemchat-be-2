<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FlowEdge extends Model
{
    protected $fillable = [
        'source_node_id',
        'target_node_id',
    ];

    public function sourceNode()
    {
        return $this->belongsTo(FlowNode::class, 'source_node_id');
    }

    public function targetNode()
    {
        return $this->belongsTo(FlowNode::class, 'target_node_id');
    }
}
