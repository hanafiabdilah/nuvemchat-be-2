<?php

namespace App\Http\Resources\Crm;

use App\Http\Resources\TagResource;
use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * A customer as a list row and as the head of the customer page. Expects
 * `contacts.tags`, `company` and `owner` eager-loaded (the list does), and
 * `last_interaction_at` from withMax when there is one.
 */
class CustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Collection<int, Contact> $contacts */
        $contacts = $this->relationLoaded('contacts') ? $this->contacts : collect();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'job_title' => $this->job_title,
            'status' => $this->status?->value,
            'source' => $this->source,
            'company' => $this->company ? ['id' => $this->company->id, 'name' => $this->company->name] : null,
            'owner' => $this->owner ? [
                'id' => $this->owner->id,
                'name' => $this->owner->name,
                'avatar' => $this->owner->avatar_url,
            ] : null,
            // The channels they write from, once each.
            'channels' => $contacts->map(fn (Contact $contact) => $contact->channel?->value)->filter()->unique()->values(),
            'contacts_count' => $contacts->count(),
            // The person's tags: the same on every linked contact (ContactTags
            // keeps them so), read as a union in case an old row disagrees.
            'tags' => TagResource::collection(
                $contacts->flatMap(fn (Contact $contact) => $contact->relationLoaded('tags') ? $contact->tags : collect())
                    ->unique('id')->values()
            ),
            'avatar' => $contacts->first(fn (Contact $contact) => filled($contact->photo_profile))?->photo_profile_url,
            'custom_fields' => (object) ($this->custom_fields ?? []),
            'last_interaction_at' => $this->last_interaction_at
                ? \Illuminate\Support\Carbon::parse($this->last_interaction_at)->toIso8601String()
                : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
