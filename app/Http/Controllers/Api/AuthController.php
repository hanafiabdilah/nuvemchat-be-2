<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\LoginThrottle;
use App\Services\Auth\TwoFactor;
use App\Services\Otp\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

class AuthController extends Controller
{
    /** Names the throttle and challenge keys, keeping them apart from the Back Office's. */
    private const SURFACE = 'tenant';

    public function __construct(
        private readonly TwoFactor $twoFactor,
    ) {}

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

    /**
     * Sign in to the dashboard.
     *
     * ⚠️ Two things this used to skip. It accepted an unlimited number of
     * guesses — no throttle, no lockout, nothing logged — and it handed out a
     * token without ever looking at `two_factor_confirmed_at`, so an account
     * that had turned a second factor on was still reachable with the password
     * alone through this endpoint. Both are closed below.
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $throttle = LoginThrottle::tenant();
        $email = (string) $request->input('email');

        $throttle->assertNotLocked($request, $email);

        if (! Auth::attempt($request->only('email', 'password'))) {
            $throttle->recordFailure($request, $email);

            return response()->json([
                'message' => 'Invalid login credentials'
            ], 401);
        }

        $user = User::where('email', $email)->first();

        $throttle->clear($request, $email);

        if ($this->hasTwoFactor($user)) {
            // Signed in as far as the password goes, but no token yet. Auth::attempt
            // put the user on the guard for this request; nothing carries over to
            // the next one, since the API is stateless.
            return response()->json([
                'two_factor' => true,
                'challenge' => $this->twoFactor->issueChallenge(self::SURFACE, $user),
                'expires_in' => 300,
            ]);
        }

        return $this->grant($user);
    }

    /**
     * Second step, for accounts with a second factor enrolled.
     *
     * ⚠️ The secret and the recovery codes are read through Fortify's own
     * helpers rather than through a cast of ours. Fortify owns how these
     * columns are written — encrypted JSON, plaintext recovery codes — and a
     * second reader with its own idea of the format is how a person ends up
     * locked out of their own account by a deploy.
     */
    public function twoFactorChallenge(Request $request)
    {
        $data = $request->validate([
            'challenge' => ['required', 'string'],
            'code' => ['required_without:recovery_code', 'nullable', 'string'],
            'recovery_code' => ['required_without:code', 'nullable', 'string'],
        ]);

        $throttle = LoginThrottle::tenant();
        $ticket = 'challenge:'.substr($data['challenge'], 0, 12);

        $throttle->assertNotLocked($request, $ticket);

        $userId = $this->twoFactor->readChallenge(self::SURFACE, $data['challenge']);
        $user = $userId ? User::find($userId) : null;

        if (! $user) {
            throw ValidationException::withMessages([
                'challenge' => __('This sign-in expired. Please enter your password again.'),
            ])->status(401);
        }

        $secret = $this->secretOf($user);

        if (! empty($data['code']) && $this->twoFactor->verify($secret, $data['code'])) {
            $this->twoFactor->consumeChallenge(self::SURFACE, $data['challenge']);
            $throttle->clear($request, $ticket);

            return $this->grant($user);
        }

        if (! empty($data['recovery_code']) && $this->spendRecoveryCode($user, $data['recovery_code'])) {
            $this->twoFactor->consumeChallenge(self::SURFACE, $data['challenge']);
            $throttle->clear($request, $ticket);

            return $this->grant($user);
        }

        $throttle->recordFailure($request, $ticket);

        throw ValidationException::withMessages([
            'code' => __('That code is not right. Check your authenticator and try again.'),
        ])->status(422);
    }

    private function grant(User $user)
    {
        $token = $user->createToken('auth_token')->plainTextToken;

        // Present from this second rather than from the first heartbeat a
        // minute later — signing in is the clearest statement there is that
        // someone is at their desk.
        $user->markSeen();

        return response()->json([
            'access_token' => $token,
            'user' => $user->toResource(UserResource::class),
        ]);
    }

    private function hasTwoFactor(?User $user): bool
    {
        return $user !== null
            && $user->two_factor_confirmed_at !== null
            && ! empty($user->two_factor_secret);
    }

    /** The TOTP secret, decrypted the way Fortify wrote it. */
    private function secretOf(User $user): ?string
    {
        try {
            return Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Spend one of Fortify's recovery codes.
     *
     * Fortify stores these in the clear inside the encrypted blob and replaces
     * a used one with a freshly generated code, which is its contract — so it
     * is used as-is rather than reimplemented here.
     */
    private function spendRecoveryCode(User $user, string $code): bool
    {
        $code = trim($code);

        try {
            $codes = (array) $user->recoveryCodes();
        } catch (\Throwable) {
            return false;
        }

        foreach ($codes as $stored) {
            if (hash_equals((string) $stored, $code)) {
                $user->replaceRecoveryCode($code);

                return true;
            }
        }

        return false;
    }
}
