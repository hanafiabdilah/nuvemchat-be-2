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
            'whatsapp_number' => $this->whatsapp_number,
            'whatsapp_verified' => $this->whatsapp_verified_at !== null,
            // Cosmetic UI state (theme preset + appearance) so a user's chosen
            // theme follows them to any browser/device, not just the one that set it.
            'ui_preferences' => $this->ui_preferences ?: null,
            // Always sent filled in (unlike ui_preferences, which may be null):
            // the dashboard has to decide whether to make a sound on the very
            // first message it receives, and a missing key there would mean
            // duplicating the "notify unless told otherwise" default client-side.
            'notification_preferences' => $this->notificationSettings(),
            'roles' => $this->whenLoaded('roles', function() {
                return $this->roles->pluck('name');
            }),
            'permissions' => $this->whenLoaded('permissions', function() {
                return $this->permissions()->orderBy('name')->pluck('name');
            }),
            'all_permissions' => $this->when($this->relationLoaded('roles') || $this->relationLoaded('permissions'), function() {
                return $this->getAllPermissions()->sortBy('name')->pluck('name')->values();
            }),
            'connections' => ConnectionResource::collection($this->whenLoaded('connections')),
        ];
    }
}
