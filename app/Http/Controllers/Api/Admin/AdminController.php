<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    /**
     * List Back Office admins (platform users with a platform role).
     */
    public function index()
    {
        $admins = User::query()
            ->whereNull('tenant_id')
            ->whereHas('roles', fn ($q) => $q->where('is_platform', true))
            ->with('roles')
            ->orderBy('id')
            ->get();

        return AdminResource::collection($admins);
    }

    /**
     * Create a new Back Office admin and assign a platform role.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'min:8'],
            'role' => ['required', 'string', Rule::exists('roles', 'name')->where('is_platform', true)],
        ]);

        $admin = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'], // hashed via cast
        ]);
        // Never tenant-scoped.
        $admin->tenant_id = null;
        $admin->save();
        $admin->syncRoles([$data['role']]);
        $admin->load('roles');

        return (new AdminResource($admin))->response()->setStatusCode(201);
    }

    /**
     * Change an admin's platform role.
     */
    public function updateRole(Request $request, User $admin)
    {
        if (! $this->isPlatformAdmin($admin)) {
            return response()->json(['message' => 'Not a Back Office admin.'], 404);
        }

        if ($admin->id === $request->user()->id) {
            return response()->json([
                'message' => 'You cannot change your own role.',
            ], 422);
        }

        $data = $request->validate([
            'role' => ['required', 'string', Rule::exists('roles', 'name')->where('is_platform', true)],
        ]);

        $admin->syncRoles([$data['role']]);
        $admin->load('roles');

        return new AdminResource($admin);
    }

    /**
     * Delete a Back Office admin.
     */
    public function destroy(Request $request, User $admin)
    {
        if (! $this->isPlatformAdmin($admin)) {
            return response()->json(['message' => 'Not a Back Office admin.'], 404);
        }

        if ($admin->id === $request->user()->id) {
            return response()->json([
                'message' => 'You cannot delete your own account.',
            ], 422);
        }

        $remaining = User::whereNull('tenant_id')
            ->whereHas('roles', fn ($q) => $q->where('is_platform', true))
            ->count();
        if ($remaining <= 1) {
            return response()->json([
                'message' => 'Cannot delete the last remaining admin.',
            ], 422);
        }

        $admin->tokens()->delete();
        $admin->delete();

        return response()->json(['message' => 'Admin deleted successfully.']);
    }

    private function isPlatformAdmin(User $user): bool
    {
        return is_null($user->tenant_id)
            && $user->roles()->where('is_platform', true)->exists();
    }
}
