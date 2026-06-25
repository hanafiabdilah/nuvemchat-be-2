<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    /** The built-in role that can't be edited or deleted. */
    private const PROTECTED_ROLE = 'super-admin';

    /**
     * List platform (Back Office) roles with their permissions.
     */
    public function index()
    {
        $roles = Role::where('is_platform', true)
            ->with(['permissions' => fn ($q) => $q->orderBy('name')])
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name'),
                'users_count' => $role->users()->count(),
                'is_protected' => $role->name === self::PROTECTED_ROLE,
            ]);

        return response()->json(['data' => $roles]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:roles,name'],
            'permissions' => ['array'],
            'permissions.*' => ['string'],
        ]);

        $role = Role::create([
            'name' => $data['name'],
            'guard_name' => 'web',
            'is_platform' => true,
        ]);
        $role->syncPermissions($this->platformPermissions($data['permissions'] ?? []));

        return response()->json(['data' => $this->present($role)], 201);
    }

    public function update(Request $request, Role $role)
    {
        if (! $role->is_platform) {
            return response()->json(['message' => 'Not a platform role.'], 404);
        }
        if ($role->name === self::PROTECTED_ROLE) {
            return response()->json(['message' => 'The super-admin role cannot be modified.'], 403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->ignore($role->id)],
            'permissions' => ['array'],
            'permissions.*' => ['string'],
        ]);

        $role->update(['name' => $data['name']]);
        $role->syncPermissions($this->platformPermissions($data['permissions'] ?? []));

        return response()->json(['data' => $this->present($role)]);
    }

    public function destroy(Role $role)
    {
        if (! $role->is_platform) {
            return response()->json(['message' => 'Not a platform role.'], 404);
        }
        if ($role->name === self::PROTECTED_ROLE) {
            return response()->json(['message' => 'The super-admin role cannot be deleted.'], 403);
        }
        if ($role->users()->count() > 0) {
            return response()->json([
                'message' => 'This role is still assigned to one or more admins.',
            ], 422);
        }

        $role->delete();

        return response()->json(['message' => 'Role deleted successfully.']);
    }

    /** Resolve permission names to platform Permission models (ignores others). */
    private function platformPermissions(array $names)
    {
        return Permission::where('is_platform', true)->whereIn('name', $names)->get();
    }

    private function present(Role $role): array
    {
        $role->load(['permissions' => fn ($q) => $q->orderBy('name')]);

        return [
            'id' => $role->id,
            'name' => $role->name,
            'permissions' => $role->permissions->pluck('name'),
            'users_count' => $role->users()->count(),
            'is_protected' => $role->name === self::PROTECTED_ROLE,
        ];
    }
}
