<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A tenant user as seen from the Back Office (platform admin) — includes the
 * customer (tenant) it belongs to and its roles.
 *
 * Expects `roles` and `tenant.user` to be eager-loaded.
 */
class AdminUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $owner = $this->tenant?->user;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')),
            'tenant' => [
                'id' => $this->tenant_id,
                'name' => $owner?->name ?? "Tenant #{$this->tenant_id}",
                'email' => $owner?->email,
                'is_owner' => $owner?->id === $this->id,
            ],
            'created_at' => $this->created_at,
        ];
    }
}
