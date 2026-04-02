<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use Illuminate\Http\Request;

class AgentController extends Controller
{
    public function index()
    {
        $users = request()->user()->tenant->users()->get();

        return response()->json([
            'data' => $users->toResourceCollection(UserResource::class),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = request()->user()->tenant->users()->create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => bcrypt($request->password),
            'role' => 'agent',
        ]);

        return response()->json([
            'message' => 'Agent created successfully',
            'data' => $user->toResource(UserResource::class),
        ], 201);
    }

    public function update(Request $request)
    {
        $user = request()->user()->tenant->users()->findOrFail($request->id);

        if($user->role === 'owner'){
            return response()->json([
                'message' => 'Owner cannot be updated',
            ], 403);
        }

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        $user->update([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password ? bcrypt($request->password) : $user->password,
        ]);

        return response()->json([
            'message' => 'Agent updated successfully',
            'data' => $user->toResource(UserResource::class),
        ], 200);
    }

    public function destroy(int $id)
    {
        $user = request()->user()->tenant->users()->findOrFail($id);

        if($user->role === 'owner'){
            return response()->json([
                'message' => 'Owner cannot be deleted',
            ], 403);
        }

        $user->delete();

        return response()->json([
            'message' => 'Agent deleted successfully',
        ], 200);
    }

    /**
     * Get connections assigned to an agent
     */
    public function getConnections(int $id)
    {
        $agent = request()->user()->tenant->users()->findOrFail($id);

        if($agent->role === 'owner'){
            return response()->json([
                'message' => 'Owner has access to all connections',
            ], 400);
        }

        $connections = $agent->connections()->where('tenant_id', request()->user()->tenant_id)->get();

        return response()->json([
            'data' => $connections,
        ], 200);
    }

    /**
     * Sync connections for an agent
     */
    public function syncConnections(int $id, Request $request)
    {
        $agent = request()->user()->tenant->users()->findOrFail($id);

        if($agent->role === 'owner'){
            return response()->json([
                'message' => 'Cannot assign connections to owner. Owners have access to all connections.',
            ], 400);
        }

        $validated = $request->validate([
            'connection_ids' => ['required', 'array'],
            'connection_ids.*' => ['required', 'exists:connections,id'],
        ]);

        // Verify all connections belong to the same tenant
        $connections = request()->user()->tenant->connections()
            ->whereIn('id', $validated['connection_ids'])
            ->pluck('id');

        // Sync connections (will add new ones and remove old ones)
        $agent->connections()->sync($connections);

        return response()->json([
            'message' => 'Agent connections synchronized successfully',
        ], 200);
    }
}
