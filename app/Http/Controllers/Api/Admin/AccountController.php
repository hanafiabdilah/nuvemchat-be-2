<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminAccountResource;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Services\Auth\TwoFactor;
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

        // Every other session this account had is now somebody else's, whether
        // that somebody is an attacker or a laptop left in a meeting room.
        // Changing a password that does not end them is a control that only
        // looks like one.
        $admin->tokens()->where('id', '!=', $request->user()->currentAccessToken()->id)->delete();

        AuditLog::record('account.password', 'Changed own password');

        return response()->json(['message' => 'Password updated successfully.']);
    }

    /**
     * Start enrolling a second factor: mint a secret, show it, confirm nothing.
     *
     * ⚠️ Guarded by the current password even though the caller is already
     * signed in. Adding a factor from a stolen session would let the thief lock
     * the real operator out of their own account, which is worse than the theft.
     *
     * ⚠️ Nothing is enforced until confirm() — see TwoFactor for why a secret
     * written straight to confirmed is how people get locked out by a QR code
     * that never scanned.
     */
    public function startTwoFactor(Request $request, TwoFactor $twoFactor)
    {
        $admin = $request->user();

        $this->assertPassword($request, $admin);

        if ($twoFactor->isEnabled($admin)) {
            return response()->json([
                'message' => 'Two-factor authentication is already on for this account.',
                'code' => 'two_factor_already_enabled',
            ], 409);
        }

        $secret = $twoFactor->newSecret();

        $admin->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        AuditLog::record('account.two_factor_started', 'Started two-factor enrolment');

        return response()->json([
            'data' => [
                // Both shapes: the QR for a phone camera, the secret for the
                // people who type it in because their authenticator lives on
                // the same machine as this browser.
                'secret' => $secret,
                'otpauth_url' => $twoFactor->provisioningUri($admin->email, $secret),
                'qr_svg' => $twoFactor->qrSvg($admin->email, $secret),
            ],
        ]);
    }

    /**
     * Finish enrolling: prove the authenticator works, then turn it on.
     *
     * The recovery codes are returned here and never again — they are stored
     * hashed, so this response is the only copy that will ever exist.
     */
    public function confirmTwoFactor(Request $request, TwoFactor $twoFactor)
    {
        $admin = $request->user();

        $data = $request->validate([
            'code' => ['required', 'string'],
        ]);

        if (empty($admin->two_factor_secret)) {
            return response()->json([
                'message' => 'Start the setup again — there is nothing waiting to be confirmed.',
                'code' => 'two_factor_not_started',
            ], 409);
        }

        if (! $twoFactor->verify($admin->two_factor_secret, $data['code'])) {
            throw ValidationException::withMessages([
                'code' => ['That code is not right. Check your authenticator and try again.'],
            ]);
        }

        $codes = $twoFactor->newRecoveryCodes();

        $admin->forceFill([
            'two_factor_recovery_codes' => $codes['hashed'],
            'two_factor_confirmed_at' => now(),
        ])->save();

        AuditLog::record('account.two_factor_enabled', 'Turned two-factor authentication on');

        return response()->json([
            'message' => 'Two-factor authentication is on.',
            'data' => ['recovery_codes' => $codes['plain']],
        ]);
    }

    /** A fresh set of recovery codes, invalidating the old ones. */
    public function regenerateRecoveryCodes(Request $request, TwoFactor $twoFactor)
    {
        $admin = $request->user();

        $this->assertPassword($request, $admin);

        if (! $twoFactor->isEnabled($admin)) {
            return response()->json([
                'message' => 'Two-factor authentication is not on for this account.',
                'code' => 'two_factor_not_enabled',
            ], 409);
        }

        $codes = $twoFactor->newRecoveryCodes();

        $admin->forceFill(['two_factor_recovery_codes' => $codes['hashed']])->save();

        AuditLog::record('account.two_factor_recovery_regenerated', 'Generated new recovery codes');

        return response()->json(['data' => ['recovery_codes' => $codes['plain']]]);
    }

    /** Turn the second factor off. Password required, for the reason above. */
    public function disableTwoFactor(Request $request, TwoFactor $twoFactor)
    {
        $admin = $request->user();

        $this->assertPassword($request, $admin);

        $admin->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        AuditLog::record('account.two_factor_disabled', 'Turned two-factor authentication off');

        return response()->json(['message' => 'Two-factor authentication is off.']);
    }

    /**
     * The current password, or a refusal.
     *
     * Keyed on `current_password` so the two screens that already ask for it
     * render the error without anything being added to them.
     */
    private function assertPassword(Request $request, Admin $admin): void
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
        ]);

        if (! Hash::check($data['current_password'], $admin->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }
    }
}
