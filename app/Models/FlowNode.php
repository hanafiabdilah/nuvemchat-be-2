<?php

namespace App\Models;

use App\Enums\Flow\NodeType;
use Illuminate\Database\Eloquent\Model;

class FlowNode extends Model
{
    protected $fillable = [
        'flow_id',
        'type',
        'data',
        'position_x',
        'position_y',
    ];

    protected $casts = [
        'type' => NodeType::class,
        'data' => 'array',
    ];

    public function flow()
    {
        return $this->belongsTo(Flow::class);
    }

    public function outgoingEdges()
    {
        return $this->hasMany(FlowEdge::class, 'source_node_id');
    }

    public function incomingEdges()
    {
        return $this->hasMany(FlowEdge::class, 'target_node_id');
    }
}
