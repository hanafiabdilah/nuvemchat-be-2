<?php

namespace App\Http\Controllers\Api;

use App\Enums\Connection\Channel;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConnectionResource;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConnectionController extends Controller
{
    public function index()
    {
        $connections = request()->user()->connections()->get();

        return response()->json([
            'data' => $connections->toResourceCollection(ConnectionResource::class),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'channel' => ['required', Rule::enum(Channel::class)],
            'name' => ['required', 'string', 'max:100'],
            'color' => ['nullable', 'hex_color', 'max:7'],
        ]);

        $connection = $request->user()->connections()->create($validated);

        return response()->json([
            'message' => 'Connection created successfully',
            'data' => $connection->toResource(ConnectionResource::class),
        ], 201);
    }
}
