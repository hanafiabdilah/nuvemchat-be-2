<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminAccountResource;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    /**
     * Update the signed-in admin's own profile.
     */
    public function updateProfile(Request $request)
    {
        $admin = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('admins', 'email')->ignore($admin->id)],
        ]);

        $admin->update($data);
        $admin->load('roles', 'permissions');

        AuditLog::record('account.profile', 'Updated own profile');

        return $admin->toResource(AdminAccountResource::class);
    }

    /**
     * Change the signed-in admin's own password.
     */
    public function updatePassword(Request $request)
    {
        $admin = $request->user();

        $data = $request->validate([
            'current_password' => ['required'],
            'password' => ['required', 'min:8', 'confirmed'],
        ]);

        if (! Hash::check($data['current_password'], $admin->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $admin->update(['password' => $data['password']]);

        AuditLog::record('account.password', 'Changed own password');

        return response()->json(['message' => 'Password updated successfully.']);
    }
}
