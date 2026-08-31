<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminResource;
use App\Models\Admin;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    /**
     * List Back Office admins.
     */
    public function index()
    {
        $admins = Admin::query()
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
            // Unique among admins only. A customer signing in to their own
            // workspace with the same address is a different account in a
            // different table, and always was in every sense but this one.
            'email' => ['required', 'email', 'unique:admins,email'],
            'password' => ['required', 'min:8'],
            'role' => ['required', 'string', Rule::exists('roles', 'name')->where('is_platform', true)],
        ]);

        $admin = Admin::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'], // hashed via cast
        ]);

        $admin->syncRoles([$data['role']]);
        $admin->load('roles');

        AuditLog::record('admin.create', "Created admin {$admin->name} ({$admin->email})", [
            'admin_id' => $admin->id,
            'role' => $data['role'],
        ]);

        return (new AdminResource($admin))->response()->setStatusCode(201);
    }

    /**
     * Change an admin's platform role.
     */
    public function updateRole(Request $request, Admin $admin)
    {
        if (! $admin->isPlatformAdmin()) {
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

        AuditLog::record('admin.role_update', "Changed {$admin->name}'s role to {$data['role']}", [
            'admin_id' => $admin->id,
            'role' => $data['role'],
        ]);

        return new AdminResource($admin);
    }

    /**
     * Delete a Back Office admin.
     */
    public function destroy(Request $request, Admin $admin)
    {
        if (! $admin->isPlatformAdmin()) {
            return response()->json(['message' => 'Not a Back Office admin.'], 404);
        }

        if ($admin->id === $request->user()->id) {
            return response()->json([
                'message' => 'You cannot delete your own account.',
            ], 422);
        }

        $remaining = Admin::whereHas('roles', fn ($q) => $q->where('is_platform', true))->count();
        if ($remaining <= 1) {
            return response()->json([
                'message' => 'Cannot delete the last remaining admin.',
            ], 422);
        }

        $name = $admin->name;
        $email = $admin->email;
        $admin->tokens()->delete();
        $admin->delete();

        AuditLog::record('admin.delete', "Deleted admin {$name} ({$email})");

        return response()->json(['message' => 'Admin deleted successfully.']);
    }
}
