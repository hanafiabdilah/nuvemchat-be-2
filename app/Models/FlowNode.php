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

    /**
     * Ordered, because the executor reads this relation with `first()`.
     *
     * Every "move on" in FlowExecutor takes one edge — `first()`, sometimes
     * after filtering by branch — and without an ORDER BY the winner is
     * whatever the storage engine hands back. That is invisible while each
     * output has one edge, and arbitrary the moment one has two: MySQL happens
     * to return the lowest id, but nothing promises it, and saving recreates
     * every edge of the flow, so the arbitrary choice was not even stable
     * across an edit.
     *
     * Duplicates are dropped on save now (FlowBlueprint::dedupeEdges), so this
     * is for the rows already out there — and it is what lets the builder mark
     * the dead edge truthfully: it calls the first one live, and so does this.
     */
    public function outgoingEdges()
    {
        return $this->hasMany(FlowEdge::class, 'source_node_id')->orderBy('id');
    }

    public function incomingEdges()
    {
        return $this->hasMany(FlowEdge::class, 'target_node_id');
    }
}
