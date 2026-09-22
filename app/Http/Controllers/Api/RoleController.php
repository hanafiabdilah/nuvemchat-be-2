<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Access\TenantRoles;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * A workspace's roles. Scoped by `roles.tenant_id` — see TenantRoles.
 */
class RoleController extends Controller
{
    /**
     * The workspace's own roles, plus the system roles (owner) it can see but
     * not change. Platform (Back Office) roles never appear, and neither do
     * platform permissions inside a role's list.
     */
    public function index(Request $request)
    {
        $roles = TenantRoles::visibleTo($request->user()->tenant_id)
            ->with(['permissions' => function ($query) {
                $query->where('is_platform', false)->orderBy('name');
            }])
            ->orderBy('created_at', 'DESC')
            ->get()
            // Said by the server rather than inferred from the name, so the
            // dashboard never has to know which names are special.
            ->each(fn (Role $role) => $role->setAttribute('is_system', TenantRoles::isSystem($role)));

        return response()->json([
            'data' => $roles,
        ]);
    }

    /**
     * Store a newly created role.
     */
    public function store(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $validated = $request->validate($this->rules($tenantId), $this->messages());

        // Not Role::create(): Spatie's version refuses a name used by *any*
        // workspace, and uniqueness is per workspace now (validated above).
        $role = Role::query()->create([
            'name' => $validated['name'],
            'guard_name' => 'web',
            'tenant_id' => $tenantId,
        ]);

        if (isset($validated['permissions'])) {
            $this->assertMayGrant($request->user(), $validated['permissions']);
            $role->syncPermissions($validated['permissions']);
        }

        $this->audit('role.created', $role, $tenantId, $validated['permissions'] ?? []);

        return response()->json([
            'message' => 'Role created successfully',
            'data' => $role->load('permissions'),
        ], 201);
    }

    /**
     * Update the specified role.
     */
    public function update(Request $request, $id)
    {
        $tenantId = $request->user()->tenant_id;

        // Another workspace's role is a 404 — its existence is not ours to confirm.
        $role = TenantRoles::visibleTo($tenantId)->findOrFail($id);

        if (TenantRoles::isSystem($role)) {
            return response()->json([
                'message' => 'Cannot update owner role',
                'code' => 'role_protected',
            ], 403);
        }

        $validated = $request->validate($this->rules($tenantId, (int) $role->id), $this->messages());

        $before = $role->permissions()->pluck('name')->sort()->values()->all();

        $role->update(['name' => $validated['name']]);

        if (isset($validated['permissions'])) {
            $this->assertMayGrant($request->user(), $validated['permissions']);
            $role->syncPermissions($validated['permissions']);
        }

        $this->audit(
            'role.updated',
            $role,
            $tenantId,
            $role->permissions()->pluck('name')->sort()->values()->all(),
            $before,
        );

        return response()->json([
            'message' => 'Role updated successfully',
            'data' => $role->load('permissions'),
        ]);
    }

    /**
     * Remove the specified role.
     */
    public function destroy(Request $request, $id)
    {
        $role = TenantRoles::visibleTo($request->user()->tenant_id)->findOrFail($id);

        // System roles (owner) are global: deleting one would strip it from
        // every workspace at once.
        if (TenantRoles::isSystem($role)) {
            return response()->json([
                'message' => 'Cannot delete owner role',
                'code' => 'role_protected',
            ], 403);
        }

        $this->audit(
            'role.deleted',
            $role,
            (int) $request->user()->tenant_id,
            $role->permissions()->pluck('name')->sort()->values()->all(),
        );

        $role->delete();

        return response()->json([
            'message' => 'Role deleted successfully',
        ]);
    }

    /**
     * Record a change to who can do what.
     *
     * ⚠️ A role is the only thing in a workspace that hands out access to other
     * people's conversations, and until now editing one left no trace at all —
     * a permission could be added on Monday and removed on Friday and nothing
     * anywhere would say it had ever been there. That is the one question an
     * incident actually asks, so the permission list travels with the row, and
     * on an edit so does the list it replaced: "who has access now" can be read
     * off the database, "what changed" cannot.
     *
     * Written to the same trail as the Back Office's own actions. That table is
     * already the platform's audit log rather than the Back Office's — revealing
     * an API Way token is a tenant user's action and lands there too — and one
     * timeline that answers "what happened to this workspace" beats two.
     *
     * @param  list<string>  $permissions
     * @param  list<string>|null  $previous
     */
    private function audit(string $action, Role $role, int $tenantId, array $permissions, ?array $previous = null): void
    {
        AuditLog::record(
            $action,
            "Role \"{$role->name}\" in workspace #{$tenantId}",
            array_filter([
                'tenant_id' => $tenantId,
                'role_id' => $role->id,
                'role' => $role->name,
                'permissions' => $permissions,
                'previous_permissions' => $previous,
            ], fn ($value) => $value !== null),
        );
    }

    /**
     * Unique inside the workspace (two workspaces may both have "Supervisor"),
     * never a reserved name, and only workspace permissions.
     *
     * @return array<string, mixed>
     */
    private function rules(int $tenantId, ?int $ignoreId = null): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('roles', 'name')
                    ->where('tenant_id', $tenantId)
                    ->where('guard_name', 'web')
                    ->ignore($ignoreId),
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (is_string($value) && TenantRoles::isReservedName($value)) {
                        $fail('This name is reserved. Choose another one.');
                    }
                },
            ],
            'permissions' => ['array'],
            'permissions.*' => [
                'string',
                // A closure, not ->where('is_platform', false): the rule's
                // string form turns `false` into '' and SQLite matches nothing.
                Rule::exists('permissions', 'name')->where(fn ($query) => $query
                    ->where('is_platform', false)
                    ->where('guard_name', 'web')),
            ],
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'name.unique' => 'A role with this name already exists.',
            'permissions.*.exists' => 'One of the selected permissions does not exist.',
        ];
    }

    /**
     * Refuse to put a permission into a role that the caller does not hold.
     *
     * ⚠️ The same rule as AgentController, and it has to be in both or it is in
     * neither: without it, somebody who may edit roles simply writes the
     * permission they want into a role and assigns it, and `roles.update`
     * quietly means every permission in the workspace.
     *
     * The owner is exempt because the owner already holds everything.
     *
     * @param  list<string>  $permissions
     */
    private function assertMayGrant(\App\Models\User $actor, array $permissions): void
    {
        if ($actor->hasRole('owner')) {
            return;
        }

        $beyond = collect($permissions)
            ->reject(fn (string $permission) => $actor->can($permission))
            ->values();

        if ($beyond->isNotEmpty()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'permissions' => [
                    'You can only grant permissions you have yourself: '.$beyond->implode(', '),
                ],
            ]);
        }
    }
}
