<?php

namespace App\Http\Resources;

use App\Services\Flow\FlowLanguages;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FlowResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // Answer each customer in their own language (FlowLocalization).
            // `source_language` is what the flow is written in once the
            // empty default is resolved — the language nothing is translated
            // for.
            'translation' => [
                'enabled' => (bool) $this->translate_to_customer,
                'language' => $this->language,
                'source_language' => FlowLanguages::ofFlow($this->resource, $request->user()?->tenant_id === $this->tenant_id ? $request->user()->tenant : null),
            ],
            'nodes' => FlowNodeResource::collection($this->whenLoaded('nodes')),
            // Present only where the query asked for it (the index): how big a
            // flow is, without sending the flow.
            'nodes_count' => $this->whenCounted('nodes'),
            'edges' => FlowEdgeResource::collection($this->whenLoaded('edges')),
            'last_updated_at' => $this->last_updated_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
