<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
            $role->syncPermissions($validated['permissions']);
        }

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

        $role->update(['name' => $validated['name']]);

        if (isset($validated['permissions'])) {
            $role->syncPermissions($validated['permissions']);
        }

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

        $role->delete();

        return response()->json([
            'message' => 'Role deleted successfully',
        ]);
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
}
