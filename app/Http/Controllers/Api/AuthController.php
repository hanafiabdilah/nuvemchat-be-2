<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Otp\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    /**
     * Self-service signup: creates the owner user + their tenant, assigns the
     * owner role (full permissions), and returns an auth token. The new tenant
     * has no subscription yet — the frontend routes to billing to subscribe.
     */
    public function register(Request $request, OtpService $otpService)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            // WhatsApp number in E.164 (client formats it); we store bare digits.
            'whatsapp_number' => ['required', 'string', 'max:32'],
        ]);

        $user = DB::transaction(function () use ($validated) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'], // hashed via model cast
                'whatsapp_number' => OtpService::normalizeNumber($validated['whatsapp_number']),
            ]);

            $tenant = Tenant::create(['user_id' => $user->id]);
            $user->tenant_id = $tenant->id;
            $user->save();

            $user->assignRole('owner');

            return $user;
        });

        // Fire off the verification OTP. Best-effort: registration must succeed even
        // if delivery fails (the code is stored and the user can resend).
        try {
            $otpService->request($user);
        } catch (\Throwable $th) {
            Log::warning('AuthController: failed to dispatch registration OTP', [
                'user_id' => $user->id,
                'error' => $th->getMessage(),
            ]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'user' => $user->load('roles', 'permissions')->toResource(UserResource::class),
        ], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if(!Auth::attempt($request->only('email', 'password'))){
            return response()->json([
                'message' => 'Invalid login credentials'
            ], 401);
        }

        $user = User::where('email', $request->email)->first();

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'user' => $user->toResource(UserResource::class),
        ]);
    }
}
