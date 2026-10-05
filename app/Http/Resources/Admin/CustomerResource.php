<?php

namespace App\Http\Resources\Admin;

use App\Services\Market\MarketDocuments;
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
            'market_code' => $this->market_code,
            'owner' => $this->whenLoaded('user', fn () => [
                'id' => $this->user?->id,
                'name' => $this->user?->name,
                'email' => $this->user?->email,
                // Bare digits (E.164 without the +), as stored. Null for legacy
                // accounts created before the number became part of signup.
                'whatsapp_number' => $this->user?->whatsapp_number,
                'whatsapp_verified' => $this->user?->whatsapp_verified_at !== null,
            ]),
            // Who is charged. The number is whole here, unlike the tenant's own
            // billing page: an operator correcting it has to see what is wrong.
            'billing' => [
                'name' => $this->billing_name,
                'document_type' => $this->billing_document_type,
                'document_number' => $this->billing_document_number,
                // What this workspace's country accepts; empty = it asks for none.
                'document_types' => MarketDocuments::forMarket($this->market_code),
            ],
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
