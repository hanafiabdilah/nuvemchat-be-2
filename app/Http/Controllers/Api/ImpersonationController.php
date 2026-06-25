<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ImpersonationController extends Controller
{
    /**
     * STEP 1 (Back Office, super-admin): mint a short-lived, single-use code
     * that the tenant app can exchange for a real session. The token itself is
     * never exposed here, so it can't leak through the redirect URL.
     */
    public function start(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
        ]);

        $target = User::find($data['user_id']);

        // Only tenant users can be impersonated — never another platform admin.
        if (! $target || is_null($target->tenant_id)) {
            return response()->json([
                'message' => 'Target user is not a tenant user and cannot be impersonated.',
            ], 422);
        }

        $admin = $request->user();
        $code = Str::random(64);

        Cache::put("impersonate:{$code}", [
            'user_id' => $target->id,
            'by_admin_id' => $admin->id,
        ], now()->addSeconds(60));

        Log::info('Back office impersonation started', [
            'admin_id' => $admin->id,
            'target_user_id' => $target->id,
            'tenant_id' => $target->tenant_id,
        ]);

        AuditLog::record(
            'impersonate.start',
            "Started impersonating {$target->name} ({$target->email})",
            ['target_user_id' => $target->id, 'tenant_id' => $target->tenant_id],
        );

        return response()->json([
            'code' => $code,
            'expires_in' => 60,
            'user' => [
                'id' => $target->id,
                'name' => $target->name,
                'email' => $target->email,
            ],
        ]);
    }

    /**
     * STEP 2 (Tenant app, public): exchange the single-use code for a Sanctum
     * session. The code is consumed atomically (pull) so it can't be replayed.
     */
    public function redeem(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $payload = Cache::pull("impersonate:{$data['code']}"); // single-use

        if (! $payload) {
            return response()->json([
                'message' => 'Invalid or expired impersonation code.',
            ], 401);
        }

        $user = User::find($payload['user_id']);

        if (! $user) {
            return response()->json([
                'message' => 'The user no longer exists.',
            ], 404);
        }

        $token = $user->createToken('impersonation', ['*'])->plainTextToken;
        $user->load('roles', 'permissions');

        Log::info('Back office impersonation redeemed', [
            'admin_id' => $payload['by_admin_id'],
            'target_user_id' => $user->id,
        ]);

        return response()->json([
            'access_token' => $token,
            'user' => $user->toResource(UserResource::class),
            'impersonated' => true,
        ]);
    }
}
