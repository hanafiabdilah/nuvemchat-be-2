<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in Back Office admin, as the Back Office needs them: identity
 * plus what they are allowed to do.
 *
 * Replaces UserResource on this surface. That one answers for a tenant user —
 * tenant_id, WhatsApp verification, notification switches, connections — none
 * of which an admin has, and half of which it would now have to invent.
 */
class AdminAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')),
            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissions()->orderBy('name')->pluck('name')),
            'all_permissions' => $this->when(
                $this->relationLoaded('roles') || $this->relationLoaded('permissions'),
                fn () => $this->getAllPermissions()->sortBy('name')->pluck('name')->values(),
            ),
        ];
    }
}
