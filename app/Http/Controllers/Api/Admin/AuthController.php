<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminAccountResource;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Services\Auth\LoginThrottle;
use App\Services\Auth\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** Names the throttle and challenge keys, keeping them apart from the dashboard's. */
    private const SURFACE = 'admin';

    public function __construct(
        private readonly TwoFactor $twoFactor,
    ) {}

    /**
     * Authenticate a Back Office (platform) admin.
     *
     * Looks in `admins` only, so a customer's credentials are not even a
     * candidate here — and an admin who happens to share an email address with
     * a customer signs in as themselves.
     *
     * ⚠️ A correct password is no longer enough to get a token. Where a second
     * factor is enrolled this answers with a challenge ticket instead, and the
     * token is issued by twoFactorChallenge(). These are the accounts that
     * reach every workspace on the platform, so the password alone was the one
     * credential worth the least and protecting the most.
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $throttle = LoginThrottle::admin();
        $email = (string) $request->input('email');

        $throttle->assertNotLocked($request, $email);

        $admin = Admin::where('email', $email)->first();

        if (! $admin || ! Hash::check($request->password, $admin->password)) {
            $throttle->recordFailure($request, $email);

            // Written down even though there is no actor to attribute it to: a
            // run of these against the Back Office is the earliest sign of an
            // attack the platform gets, and it used to leave no trace at all.
            AuditLog::record('auth.login_failed', 'Failed Back Office sign-in', [
                'email' => $email,
                'reason' => $admin ? 'bad_password' : 'unknown_account',
            ]);

            return response()->json([
                'message' => 'Invalid login credentials',
            ], 401);
        }

        if (! $admin->isPlatformAdmin()) {
            // Not counted as a guess: the password was right. This is an
            // account that exists and is not allowed in, which is a different
            // event and must not fill up a lockout budget.
            AuditLog::record('auth.login_denied', 'Sign-in by an account with no platform role', [
                'email' => $email,
            ], actor: $admin);

            return response()->json([
                'message' => 'This account is not allowed to access the Back Office.',
            ], 403);
        }

        $throttle->clear($request, $email);

        if ($this->twoFactor->isEnabled($admin)) {
            return response()->json([
                'two_factor' => true,
                'challenge' => $this->twoFactor->issueChallenge(self::SURFACE, $admin),
                'expires_in' => 300,
            ]);
        }

        return $this->grant($request, $admin);
    }

    /**
     * Second step: the code from the authenticator, or a recovery code.
     *
     * Rate limited on its own budget, because the ticket survives a wrong code
     * on purpose (see TwoFactor::issueChallenge) and six digits are only out of
     * reach while guessing is bounded.
     */
    public function twoFactorChallenge(Request $request)
    {
        $data = $request->validate([
            'challenge' => ['required', 'string'],
            'code' => ['required_without:recovery_code', 'nullable', 'string'],
            'recovery_code' => ['required_without:code', 'nullable', 'string'],
        ]);

        $throttle = LoginThrottle::admin();
        $throttle->assertNotLocked($request, 'challenge:'.substr($data['challenge'], 0, 12));

        $adminId = $this->twoFactor->readChallenge(self::SURFACE, $data['challenge']);
        $admin = $adminId ? Admin::find($adminId) : null;

        if (! $admin) {
            throw ValidationException::withMessages([
                'challenge' => __('This sign-in expired. Please enter your password again.'),
            ])->status(401);
        }

        if (! empty($data['code']) && $this->twoFactor->verify($admin->two_factor_secret, $data['code'])) {
            return $this->completeChallenge($request, $admin, $data['challenge'], 'code');
        }

        if (! empty($data['recovery_code'])) {
            $codes = (array) ($admin->two_factor_recovery_codes ?? []);

            if ($this->twoFactor->consumeRecoveryCode($codes, $data['recovery_code'])) {
                // Written back before the token is issued: a code that let
                // somebody in and stayed valid is a password that never expires.
                $admin->forceFill(['two_factor_recovery_codes' => $codes])->save();

                AuditLog::record('auth.two_factor_recovery_used', 'Signed in with a recovery code', [
                    'remaining' => count($codes),
                ], actor: $admin);

                return $this->completeChallenge($request, $admin, $data['challenge'], 'recovery_code');
            }
        }

        $throttle->recordFailure($request, 'challenge:'.substr($data['challenge'], 0, 12));

        AuditLog::record('auth.two_factor_failed', 'Wrong second factor', [], actor: $admin);

        throw ValidationException::withMessages([
            'code' => __('That code is not right. Check your authenticator and try again.'),
        ])->status(422);
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

    private function completeChallenge(Request $request, Admin $admin, string $challenge, string $via)
    {
        $this->twoFactor->consumeChallenge(self::SURFACE, $challenge);
        LoginThrottle::admin()->clear($request, 'challenge:'.substr($challenge, 0, 12));

        return $this->grant($request, $admin, $via);
    }

    /** Issue the token and record the sign-in. */
    private function grant(Request $request, Admin $admin, string $via = 'password')
    {
        // Token gets the `admin` ability so it can't be reused on tenant routes.
        $token = $admin->createToken('admin_token', ['admin'])->plainTextToken;

        AuditLog::record('auth.login', 'Signed in to the Back Office', [
            'via' => $via,
        ], actor: $admin);

        $admin->load('roles', 'permissions');

        return response()->json([
            'access_token' => $token,
            'user' => $admin->toResource(AdminAccountResource::class),
        ]);
    }
}
