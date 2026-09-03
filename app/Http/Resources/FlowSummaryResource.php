<?php

namespace App\Http\Resources;

use App\Models\FlowEdge;
use App\Models\FlowNode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A flow as the list needs it: its name, its size, and its shape.
 *
 * Separate from FlowResource because the list and the builder want different
 * things and one resource serving both ends up shipping the larger. `preview`
 * carries node types and coordinates so the card can draw a thumbnail of the
 * actual flow — never `data`, which is the message bodies, AI prompts and HTTP
 * headers the builder edits. A screen showing twenty flows must not ship
 * twenty flows.
 */
class FlowSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'nodes_count' => $this->whenCounted('nodes'),
            'last_updated_at' => $this->last_updated_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'preview' => $this->when($this->resource->relationLoaded('nodes'), fn () => [
                'nodes' => $this->nodes->map(fn (FlowNode $node) => [
                    'id' => $node->id,
                    'type' => $node->type->value,
                    'x' => (float) $node->position_x,
                    'y' => (float) $node->position_y,
                ])->values(),
                'edges' => $this->resource->relationLoaded('edges')
                    ? $this->edges->map(fn (FlowEdge $edge) => [
                        'source' => $edge->source_node_id,
                        'target' => $edge->target_node_id,
                    ])->values()
                    : [],
            ]),
        ];
    }
}
