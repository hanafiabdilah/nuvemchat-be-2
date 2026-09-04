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
            // Direct grants, whatever the roles also happen to give.
            'permissions' => $this->whenLoaded('permissions', function() {
                return $this->permissions()->orderBy('name')->pluck('name');
            }),
            'all_permissions' => $this->when($this->relationLoaded('roles') || $this->relationLoaded('permissions'), function() {
                return $this->getAllPermissions()->sortBy('name')->pluck('name')->values();
            }),
            // What the roles alone grant, and what was granted on top of them.
            //
            // The difference is the whole point of the split: a role is the
            // description of a job and an extra permission is an exception to
            // it, and a list that mixed the two could not show either. A direct
            // grant the role already covers is not an exception — it changes
            // nothing about what the agent can do — so it is not counted here.
            'role_permissions' => $this->whenLoaded('roles', function() {
                return $this->getPermissionsViaRoles()->sortBy('name')->pluck('name')->values();
            }),
            'extra_permissions' => $this->when($this->relationLoaded('permissions'), function() {
                $viaRoles = $this->relationLoaded('roles')
                    ? $this->getPermissionsViaRoles()->pluck('name')->all()
                    : [];

                return $this->permissions
                    ->pluck('name')
                    ->reject(fn (string $name) => in_array($name, $viaRoles, true))
                    ->sort()
                    ->values();
            }),
            'connections' => ConnectionResource::collection($this->whenLoaded('connections')),
        ];
    }
}
