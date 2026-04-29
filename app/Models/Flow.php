<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Flow extends Model
{
    protected $fillable = [
        'name'
    ];

    public function nodes()
    {
        return $this->hasMany(FlowNode::class);
    }

    public function edges()
    {
        // Get edges where source_node_id belongs to this flow's nodes
        return $this->hasManyThrough(
            FlowEdge::class,
            FlowNode::class,
            'flow_id',        // Foreign key on FlowNode table
            'source_node_id', // Foreign key on FlowEdge table
            'id',             // Local key on Flow table
            'id'              // Local key on FlowNode table
        );
    }
}
