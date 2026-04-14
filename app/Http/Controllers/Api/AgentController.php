<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConnectionResource;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;

class AgentController extends Controller
{
    public function index()
    {
        $users = request()->user()->tenant->users()->with('connections')->get();

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
            'roles' => ['nullable', 'array'],
            'roles.*' => ['exists:roles,name'],
        ]);

        $user = request()->user()->tenant->users()->create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => bcrypt($request->password),
        ]);

        // Assign roles if provided (optional, no default role)
        if ($request->has('roles') && !empty($request->roles)) {
            // Prevent assigning owner role
            $roles = array_diff($request->roles, ['owner']);
            if (!empty($roles)) {
                $user->assignRole($roles);
            }
        }

        return response()->json([
            'message' => 'Agent created successfully',
            'data' => $user->toResource(UserResource::class),
        ], 201);
    }

    public function update(Request $request)
    {
        $user = request()->user()->tenant->users()->findOrFail($request->id);

        if($user->hasRole('owner')){
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

        if($user->hasRole('owner')){
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
     * Sync connections for an agent
     */
    public function syncConnections(int $id, Request $request)
    {
        $agent = request()->user()->tenant->users()->findOrFail($id);

        if($agent->hasRole('owner')){
            return response()->json([
                'message' => 'Cannot assign connections to owner. Owners have access to all connections.',
            ], 400);
        }

        $validated = $request->validate([
            'connection_ids' => ['nullable', 'array'],
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

    /**
     * Get agent roles and permissions.
     */
    public function getRolesAndPermissions(int $id)
    {
        $user = request()->user()->tenant->users()->with(['roles', 'permissions'])->findOrFail($id);

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->roles,
                'permissions' => $user->permissions,
                'all_permissions' => $user->getAllPermissions(),
            ],
        ]);
    }

    /**
     * Assign roles to agent.
     */
    public function assignRoles(Request $request, int $id)
    {
        $user = request()->user()->tenant->users()->findOrFail($id);

        // Prevent assigning roles to owner users
        if ($user->hasRole('owner')) {
            return response()->json([
                'message' => 'Cannot assign roles to owner',
            ], 403);
        }

        $validated = $request->validate([
            'roles' => 'required|array',
            'roles.*' => 'exists:roles,name',
        ]);

        // Prevent assigning owner role
        if (in_array('owner', $validated['roles'])) {
            return response()->json([
                'message' => 'Cannot assign owner role',
            ], 403);
        }

        $user->syncRoles($validated['roles']);

        return response()->json([
            'message' => 'Roles assigned successfully',
            'data' => $user->load('roles'),
        ]);
    }

    /**
     * Assign permissions to agent.
     */
    public function assignPermissions(Request $request, int $id)
    {
        $user = request()->user()->tenant->users()->findOrFail($id);

        // Prevent assigning permissions to owner users
        if ($user->hasRole('owner')) {
            return response()->json([
                'message' => 'Cannot assign permissions to owner',
            ], 403);
        }

        $validated = $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'exists:permissions,name',
        ]);

        $user->syncPermissions($validated['permissions']);

        return response()->json([
            'message' => 'Permissions assigned successfully',
            'data' => $user->load('permissions'),
        ]);
    }
}
