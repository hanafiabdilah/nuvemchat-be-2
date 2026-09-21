<?php

namespace App\Http\Controllers\Api;

use App\Enums\Notification\NotificationType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Notification\NotificationService;
use App\Services\Otp\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Forgotten-password recovery over WhatsApp OTP, in three public steps:
 *
 *   1. forgot  — email in, a code goes out to the account's WhatsApp number
 *   2. verify  — code checked (not consumed) so the UI can advance to the new password
 *   3. reset   — code checked and consumed, password replaced, sessions revoked
 *
 * The code is only re-checked, never trusted from the client between steps, so step 3
 * is safe on its own even if step 2 is skipped.
 *
 * All three answer with the same generic shape whether or not the email exists — this
 * endpoint is unauthenticated, so it must not become an account-enumeration oracle.
 * Rate limiting lives on the route (see routes/api.php).
 */
class PasswordResetController extends Controller
{
    public function __construct(
        protected OtpService $otpService,
        protected NotificationService $notifications,
    ) {}

    /** Step 1 — issue a reset code to the account's WhatsApp number. */
    public function forgot(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if ($user && $user->whatsapp_number) {
            try {
                $this->otpService->request($user, OtpService::PURPOSE_PASSWORD_RESET);
            } catch (ValidationException) {
                // Almost always the resend cooldown. Swallowed on purpose: a 422 here
                // would tell an anonymous caller that the address is registered.
            } catch (\Throwable $th) {
                Log::warning('PasswordResetController: failed to dispatch reset OTP', [
                    'user_id' => $user->id,
                    'error' => $th->getMessage(),
                ]);
            }
        } elseif ($user) {
            // Recovery runs over WhatsApp only, so a legacy account with no number on file
            // has no self-service path. The response stays generic; support needs the trail.
            Log::notice('PasswordResetController: reset requested for an account with no WhatsApp number', [
                'user_id' => $user->id,
            ]);
        }

        // ⚠️ Byte-for-byte the same answer whether or not the address is
        // registered. The docblock above always claimed this, and the response
        // did not: `whatsapp_number` came back masked for an account that
        // existed and null for one that did not, so an anonymous caller could
        // confirm any address — and collect the last four digits of the
        // owner's phone number, which is exactly what a help desk asks for to
        // prove identity.
        //
        // `cooldown` was the second tell: it carried the *remaining* seconds
        // for a real account and a flat 60 otherwise. It is the full window
        // for everyone now; the client only uses it to disable a button.
        return response()->json([
            'message' => 'If an account exists for that email, a reset code was sent to its WhatsApp number.',
            'cooldown' => OtpService::resendCooldownSeconds(),
            'expires_in_minutes' => OtpService::ttlMinutes(),
        ]);
    }

    /** Step 2 — confirm the code is valid without spending it. */
    public function verify(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'code' => ['required', 'string', 'min:4', 'max:10'],
        ]);

        $user = $this->userOrFail($validated['email']);

        $this->otpService->check($user, $validated['code'], OtpService::PURPOSE_PASSWORD_RESET);

        return response()->json(['message' => 'Code verified.', 'verified' => true]);
    }

    /** Step 3 — consume the code and set the new password. */
    public function reset(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'code' => ['required', 'string', 'min:4', 'max:10'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $this->userOrFail($validated['email']);

        $this->otpService->verify($user, $validated['code'], OtpService::PURPOSE_PASSWORD_RESET);

        $user->forceFill(['password' => $validated['password']])->save(); // hashed via model cast

        // Whoever held a session before the reset no longer should — that includes an
        // attacker who prompted the recovery in the first place.
        $user->tokens()->delete();

        // Out-of-band heads-up: if the owner did not do this, this message is how they find out.
        $this->notifications->send(NotificationType::PasswordChanged, (string) $user->whatsapp_number, [
            'name' => $user->name,
            // On the workspace's clock and in its country's notation. "Changed
            // at 14:30" is the one fact in this message a person checks against
            // their own memory, and a UTC timestamp makes a legitimate change
            // look like it happened while they were asleep.
            'datetime' => $user->tenant?->formatDateTime(now()) ?: now()->format('d/m/Y H:i'),
        ], $user->id);

        return response()->json(['message' => 'Password updated. Please log in with your new password.']);
    }

    /**
     * Resolve the account, presenting a missing one as a bad code rather than as a
     * missing account (same reason forgot() stays generic).
     */
    private function userOrFail(string $email): User
    {
        $user = User::where('email', $email)->first();

        if (! $user) {
            throw ValidationException::withMessages(['code' => 'Incorrect code. Please try again.']);
        }

        return $user;
    }
}
