<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Authenticate a Back Office (platform) admin.
     *
     * Unlike the tenant login, this only allows users holding the
     * `super-admin` role with no tenant scope.
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Invalid login credentials',
            ], 401);
        }

        $isPlatformAdmin = is_null($user->tenant_id)
            && $user->roles()->where('is_platform', true)->exists();

        if (! $isPlatformAdmin) {
            return response()->json([
                'message' => 'This account is not allowed to access the Back Office.',
            ], 403);
        }

        // Token gets the `admin` ability so it can't be reused on tenant routes.
        $token = $user->createToken('admin_token', ['admin'])->plainTextToken;

        $user->load('roles', 'permissions');

        return response()->json([
            'access_token' => $token,
            'user' => $user->toResource(UserResource::class),
        ]);
    }

    /**
     * Return the currently authenticated admin.
     */
    public function me(Request $request)
    {
        $user = $request->user();
        $user->load('roles', 'permissions');

        return $user->toResource(UserResource::class);
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
