<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A connected MCP client, as the dashboard lists it.
 *
 * No token, no client secret, no client_id: none of them tell the person
 * anything they can use, and the client_id in particular is a long opaque
 * string that would crowd out the name — which is the only thing they recognise
 * a connection by.
 *
 * @mixin \App\Models\McpConnection
 */
class McpConnectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $this->getRelationValue('user');

        return [
            'id' => $this->id,
            'client_name' => $this->client_name,
            'scopes' => $this->scopeList(),
            // Who approved it. An owner looking at this list is deciding whose
            // connection to cut, so the name is the point.
            'approved_by' => $user ? ['id' => $user->id, 'name' => $user->name] : null,
            'is_mine' => $user?->id === $request->user()?->id,
            'created_at' => $this->created_at?->toIso8601String(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
        ];
    }
}
