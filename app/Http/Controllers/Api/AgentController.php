<?php

namespace App\Http\Controllers\Api;

use App\Events\ConnectionAccessUpdated;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConnectionResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\User\AvatarStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentController extends Controller
{
    public function index()
    {
        $users = request()->user()->tenant->users()->with(['connections', 'roles', 'permissions'])->orderBy('created_at', 'DESC')->get();

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

        $tenant = request()->user()->tenant;

        if (! app(\App\Services\Billing\SubscriptionGate::class)->canConsume($tenant, 'max_agents', $tenant->users()->count())) {
            return response()->json([
                'message' => 'You have reached the maximum number of agents for your plan.',
                'code' => 'quota_exceeded',
                'quota' => 'max_agents',
            ], 422);
        }

        $user = $tenant->users()->create([
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

    /**
     * Set an agent's photo, on behalf of whoever manages the roster.
     *
     * Separate from update() rather than another field on it: this is a
     * multipart upload, and it is reachable with `agents.update-avatar` alone —
     * putting a face on a colleague's row should not require the permission
     * that changes the e-mail and password they sign in with.
     */
    public function updateAvatar(Request $request, int $id, AvatarStorage $avatars)
    {
        $user = $this->manageableAgent($id);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        $request->validate(['avatar' => AvatarStorage::rules()]);

        $avatars->store($user, $request->file('avatar'));

        return response()->json([
            'message' => 'Agent photo updated successfully',
            'data' => $this->agentPayload($user),
        ]);
    }

    public function destroyAvatar(int $id, AvatarStorage $avatars)
    {
        $user = $this->manageableAgent($id);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        $avatars->clear($user);

        return response()->json([
            'message' => 'Agent photo removed successfully',
            'data' => $this->agentPayload($user),
        ]);
    }

    /**
     * The agent this request may act on, or the refusal to send back instead.
     *
     * Owners are off-limits here for the same reason they are in update(),
     * destroy() and the two assign* methods: an owner's account is edited by
     * the owner, on their own profile page.
     */
    private function manageableAgent(int $id): User|JsonResponse
    {
        $user = request()->user()->tenant->users()->findOrFail($id);

        if ($user->hasRole('owner')) {
            return response()->json([
                'message' => 'Owner cannot be updated',
            ], 403);
        }

        return $user;
    }

    /** The same shape index() returns, so a row can be replaced wholesale. */
    private function agentPayload(User $user): UserResource
    {
        return $user->fresh()
            ->load(['connections', 'roles', 'permissions'])
            ->toResource(UserResource::class);
    }

    public function destroy(int $id, AvatarStorage $avatars)
    {
        $user = request()->user()->tenant->users()->findOrFail($id);

        if($user->hasRole('owner')){
            return response()->json([
                'message' => 'Owner cannot be deleted',
            ], 403);
        }

        // Nothing else ever revisits this file — there is no sweep over the
        // avatar directory — so the row going away is the only moment its photo
        // can be collected.
        $avatars->forget($user);

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
            ->whereIn('id', $validated['connection_ids'] ?? [])
            ->pluck('id');

        // Sync connections (will add new ones and remove old ones)
        $agent->connections()->sync($connections);

        // Tell the agent's own session so the change lands now rather than at
        // their next login — which matters most for a revoke, since their tab
        // is still subscribed to the connection channel and still holds the
        // history in IndexedDB until it is told to let go.
        broadcast(new ConnectionAccessUpdated($agent->fresh()));

        return response()->json([
            'message' => 'Agent connections synchronized successfully',
        ], 200);
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
