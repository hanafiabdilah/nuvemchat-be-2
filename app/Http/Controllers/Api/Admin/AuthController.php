<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminAccountResource;
use App\Models\Admin;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Authenticate a Back Office (platform) admin.
     *
     * Looks in `admins` only, so a customer's credentials are not even a
     * candidate here — and an admin who happens to share an email address with
     * a customer signs in as themselves.
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $admin = Admin::where('email', $request->email)->first();

        if (! $admin || ! Hash::check($request->password, $admin->password)) {
            return response()->json([
                'message' => 'Invalid login credentials',
            ], 401);
        }

        if (! $admin->isPlatformAdmin()) {
            return response()->json([
                'message' => 'This account is not allowed to access the Back Office.',
            ], 403);
        }

        // Token gets the `admin` ability so it can't be reused on tenant routes.
        $token = $admin->createToken('admin_token', ['admin'])->plainTextToken;

        AuditLog::record('auth.login', 'Signed in to the Back Office', actor: $admin);

        $admin->load('roles', 'permissions');

        return response()->json([
            'access_token' => $token,
            'user' => $admin->toResource(AdminAccountResource::class),
        ]);
    }

    /**
     * Return the currently authenticated admin.
     */
    public function me(Request $request)
    {
        $admin = $request->user();
        $admin->load('roles', 'permissions');

        return $admin->toResource(AdminAccountResource::class);
    }

    /**
     * Revoke the current access token.
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }
}
