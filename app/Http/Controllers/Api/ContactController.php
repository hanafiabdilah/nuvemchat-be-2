<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContactResource;
use App\Models\Contact;
use Illuminate\Http\Request;

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
            ->paginate($per_page);

        return ContactResource::collection($contacts);
    }
}
