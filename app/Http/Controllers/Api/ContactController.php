<?php

namespace App\Http\Controllers\Api;

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

        $contacts = Contact::where('tenant_id', $request->user()->tenant_id)
            ->when($search, function ($query, $search) {
                $query->where('name', 'like', "%{$search}%")
                      ->orWhere('username', 'like', "%{$search}%");
            })
            ->when($channel, function ($query, $channel) {
                $query->where('channel', $channel);
            })
            ->orderBy('created_at', 'desc')
            ->paginate($per_page);

        return ContactResource::collection($contacts);
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
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}
