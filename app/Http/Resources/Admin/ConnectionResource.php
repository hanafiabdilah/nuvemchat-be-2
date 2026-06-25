<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A channel connection as seen from the Back Office.
 *
 * Deliberately omits `credentials` and `api_key` — those are tenant secrets
 * and must never reach the platform admin UI.
 *
 * Expects `tenant.user` eager-loaded and `conversations_count` via withCount.
 */
class ConnectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $owner = $this->tenant?->user;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'channel' => $this->channel?->value,
            'status' => $this->status?->value,
            'color' => $this->color,
            'tenant' => [
                'id' => $this->tenant_id,
                'name' => $owner?->name ?? "Tenant #{$this->tenant_id}",
            ],
            'conversations_count' => $this->conversations_count ?? 0,
            'created_at' => $this->created_at,
        ];
    }
}
