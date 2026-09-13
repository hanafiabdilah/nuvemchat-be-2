<?php

namespace App\Http\Controllers\Api;

use App\Enums\Conversation\Status;
use App\Events\ConnectionAccessUpdated;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConnectionResource;
use App\Http\Resources\UserResource;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Access\TenantRoles;
use App\Services\User\AgentRemoval;
use App\Services\User\AvatarStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AgentController extends Controller
{
    public function index()
    {
        $users = request()->user()->tenant->users()->with(['connections', 'roles', 'permissions'])->orderBy('created_at', 'DESC')->get();

        // How many unresolved conversations each person holds: the number the
        // delete dialog has to decide about. One grouped query for the page,
        // kept beside the list rather than in UserResource, which half the app
        // serializes.
        $open = Conversation::query()
            ->whereIn('user_id', $users->pluck('id'))
            ->where('status', '!=', Status::Resolved->value)
            ->groupBy('user_id')
            ->selectRaw('user_id, COUNT(*) as aggregate')
            ->pluck('aggregate', 'user_id')
            ->map(fn ($count) => (int) $count);

         return response()->json([
            'data' => $users->toResourceCollection(UserResource::class),
            'open_conversations' => (object) $open->all(),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string'],
        ]);

        $tenant = request()->user()->tenant;

        // Resolved before the account exists, so a role from another workspace
        // (or one deleted a moment ago) fails the request instead of leaving a
        // half-made agent behind. The owner role is still dropped silently, as
        // it always was: it is never offered, and nobody is made owner here.
        $roles = TenantRoles::resolve(
            $tenant->id,
            collect($request->input('roles', []))
                ->reject(fn ($name) => is_string($name) && mb_strtolower($name) === 'owner')
                ->all(),
        );

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

        // Optional, no default role. Models, never names — see TenantRoles.
        if ($roles->isNotEmpty()) {
            $user->syncRoles($roles);
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

    /**
     * Remove a person, keeping everything they worked on.
     *
     * `reassign_to` (optional, query or body) names who receives their open
     * conversations; without it — or for any conversation on a connection that
     * person cannot reach — the conversation goes back to the queue. Resolved
     * history stays, unassigned. See AgentRemoval for what happens to the rest.
     */
    public function destroy(Request $request, int $id, AgentRemoval $removal)
    {
        $actor = $request->user();
        $user = $actor->tenant->users()->findOrFail($id);

        if($user->hasRole('owner')){
            return response()->json([
                'message' => 'Owner cannot be deleted',
            ], 403);
        }

        $validated = $request->validate([
            'reassign_to' => ['nullable', 'integer'],
        ]);

        $target = null;

        if (! empty($validated['reassign_to'])) {
            $target = $actor->tenant->users()->find($validated['reassign_to']);

            if (! $target || (int) $target->id === (int) $user->id) {
                throw ValidationException::withMessages([
                    'reassign_to' => 'Choose another person from this workspace to receive the conversations.',
                ]);
            }
        }

        $summary = $removal->remove($user, $actor, $target);

        return response()->json([
            'message' => 'Agent deleted successfully',
            'data' => $summary,
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

        // `present`, not `required`: an empty list is a real answer — it takes
        // the last role away. `required` refused it, so nobody could be left
        // with no role once they had one.
        $validated = $request->validate([
            'roles' => ['present', 'array'],
            'roles.*' => ['string'],
        ]);

        // Prevent assigning owner role
        if (collect($validated['roles'])->contains(fn ($name) => mb_strtolower((string) $name) === 'owner')) {
            return response()->json([
                'message' => 'Cannot assign owner role',
            ], 403);
        }

        // Only this workspace's roles, as models: a name shared with another
        // workspace must not resolve to theirs.
        $user->syncRoles(TenantRoles::resolve($user->tenant_id, $validated['roles']));

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

        // Empty is allowed (it removes the last extra permission), and only
        // workspace permissions exist here — never the Back Office's.
        $validated = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => [
                'string',
                // A closure, not ->where('is_platform', false): the rule's
                // string form turns `false` into '' and SQLite matches nothing.
                Rule::exists('permissions', 'name')->where(fn ($query) => $query
                    ->where('is_platform', false)
                    ->where('guard_name', 'web')),
            ],
        ], [
            'permissions.*.exists' => 'One of the selected permissions does not exist.',
        ]);

        $user->syncPermissions($validated['permissions']);

        return response()->json([
            'message' => 'Permissions assigned successfully',
            'data' => $user->load('permissions'),
        ]);
    }
}
