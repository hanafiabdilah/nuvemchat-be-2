<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
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
            'email' => $this->email,
            'tenant_id' => $this->tenant_id,
            'roles' => $this->whenLoaded('roles', function() {
                return $this->roles->pluck('name');
            }),
            'permissions' => $this->whenLoaded('permissions', function() {
                return $this->permissions->pluck('name');
            }),
            'all_permissions' => $this->when($this->relationLoaded('roles') || $this->relationLoaded('permissions'), function() {
                return $this->getAllPermissions()->pluck('name');
            }),
            'connections' => ConnectionResource::collection($this->whenLoaded('connections')),
        ];
    }
}
