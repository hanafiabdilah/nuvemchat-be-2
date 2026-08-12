<?php

namespace App\Http\Controllers\Api;

use App\Enums\Connection\Channel;
use App\Http\Controllers\Controller;
use App\Http\Resources\ContactResource;
use App\Models\Connection;
use App\Models\Contact;
use App\Services\Contact\ContactService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ContactController extends Controller
{
    public function index(Request $request)
    {
        $per_page = $request->query('per_page', 50);
        $search = $request->query('search');
        $channel = $request->query('channel');
        $addressType = $request->query('address_type');

        // Group contacts represent group chats, not people — keep them out of
        // the contact book (and out of the new-conversation picker).
        $contacts = Contact::where('tenant_id', $request->user()->tenant_id)
            ->where('is_group', false)
            ->when($search, function ($query, $search) {
                $query->where('name', 'like', "%{$search}%")
                      ->orWhere('username', 'like', "%{$search}%")
                      ->orWhere('external_id', 'like', "%{$search}%");
            })
            ->when($channel, function ($query, $channel) {
                $query->where('channel', $channel);
            })
            // Broader than `channel`, for the broadcast recipient picker: a
            // contact saved under API Way is reachable by a WhatsApp Official
            // campaign too, because both address people by phone number.
            ->when($addressType, function ($query, $addressType) {
                $query->whereIn('channel', $this->channelsAddressedBy($addressType));
            })
            ->orderBy('created_at', 'desc')
            ->paginate($per_page);

        return ContactResource::collection($contacts);
    }

    /**
     * Channels whose contacts share an address shape, so a campaign on one can
     * reach contacts saved under another.
     *
     * @return array<int, string>
     */
    private function channelsAddressedBy(string $addressType): array
    {
        return collect(Channel::cases())
            ->filter(fn (Channel $channel) => $channel->broadcastAddressType()->value === $addressType)
            ->map(fn (Channel $channel) => $channel->value)
            ->values()
            ->all();
    }

    public function store(Request $request)
    {
        // Validate request
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone_number' => ['required', 'string'],
            'connection_id' => ['required', 'exists:connections,id'],
        ]);

        // Get connection and verify it belongs to the user's tenant
        $connection = Connection::where('id', $validated['connection_id'])
            ->where('tenant_id', $request->user()->tenant_id)
            ->firstOrFail();

        try {
            $contactService = new ContactService();
            $contact = $contactService->addContact($connection, $validated);

            return new ContactResource($contact);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create contact',
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            // Campaign opt-out. Usually set by the customer replying "PARAR"
            // (see OptOutDetector), but an agent has to be able to record a
            // request that came in by phone, and to undo a false positive.
            'broadcast_opted_out' => ['sometimes', 'boolean'],
        ]);

        $contact = Contact::where('id', $id)
            ->where('tenant_id', $request->user()->tenant_id)
            ->firstOrFail();

        if (array_key_exists('name', $validated)) {
            $validated['name_locked'] = true;
        }

        if (array_key_exists('broadcast_opted_out', $validated)) {
            $validated['broadcast_opted_out_at'] = $validated['broadcast_opted_out'] ? now() : null;
            unset($validated['broadcast_opted_out']);
        }

        $contact->update($validated);

        return response()->json([
            'message' => 'Contact updated successfully',
            'contact' => new ContactResource($contact),
        ]);
    }
}
