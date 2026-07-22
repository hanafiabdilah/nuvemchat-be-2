<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Represents a "customer" (Tenant) for the Back Office.
 *
 * Expects the Tenant to be loaded with its `user` (owner) relation and
 * the `users_count`, `connections_count`, `contacts_count`,
 * `conversations_count` aggregates (via withCount).
 */
class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'owner' => $this->whenLoaded('user', fn () => [
                'id' => $this->user?->id,
                'name' => $this->user?->name,
                'email' => $this->user?->email,
                // Bare digits (E.164 without the +), as stored. Null for legacy
                // accounts created before the number became part of signup.
                'whatsapp_number' => $this->user?->whatsapp_number,
                'whatsapp_verified' => $this->user?->whatsapp_verified_at !== null,
            ]),
            'counts' => [
                'users' => $this->users_count ?? 0,
                'connections' => $this->connections_count ?? 0,
                'contacts' => $this->contacts_count ?? 0,
                'conversations' => $this->conversations_count ?? 0,
            ],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
