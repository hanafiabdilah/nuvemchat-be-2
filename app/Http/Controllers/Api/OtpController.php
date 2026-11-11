<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Otp\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * WhatsApp number verification for the signed-in user (post-registration).
 * Not gated by the subscription middleware so a brand-new tenant can verify
 * before subscribing.
 */
class OtpController extends Controller
{
    public function __construct(protected OtpService $otpService) {}

    /** Current verification state + resend cooldown, for the verify screen. */
    public function status()
    {
        $user = Auth::user();

        return response()->json([
            'whatsapp_number' => $this->mask($user->whatsapp_number),
            // In full as well: a mistyped number is only noticed when it can be
            // read, and the last four digits are rarely where the typo is. Not
            // a new disclosure — GET /user already returns it to this session.
            'whatsapp_number_full' => $user->whatsapp_number ? OtpService::normalizeNumber($user->whatsapp_number) : null,
            'verified' => $user->whatsapp_verified_at !== null,
            'cooldown' => $this->otpService->cooldownRemaining($user),
            // So the screen opens already showing a block, rather than letting
            // somebody type a code only to be told they cannot.
            'blocked_for' => $this->otpService->blockedFor($user),
            'support_email' => config('app.support_email') ?: null,
        ]);
    }

    /** (Re)send a verification code to the user's WhatsApp number. */
    public function send()
    {
        $user = Auth::user();

        if ($user->whatsapp_verified_at !== null) {
            return response()->json(['message' => 'WhatsApp number already verified.'], 422);
        }

        $this->otpService->request($user);

        return response()->json([
            'message' => 'Verification code sent.',
            'cooldown' => $this->otpService->cooldownRemaining($user),
        ]);
    }

    /** Verify a submitted code and mark the number confirmed. */
    public function verify(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'min:4', 'max:10'],
        ]);

        $user = Auth::user();

        $this->otpService->verify($user, $validated['code']);

        return response()->json([
            'message' => 'WhatsApp number verified.',
            'verified' => true,
        ]);
    }

    /**
     * The number typed at signup was wrong: replace it and send the code to the
     * right one.
     *
     * Costs the current password, like changing a verified number does: this is
     * also the number a password reset is sent to, so it is never handed to
     * whoever merely holds the session. Only while the number is unverified —
     * a verified one is changed from Settings → Notifications, which keeps the
     * old number until the new one is confirmed.
     */
    public function changeNumber(Request $request)
    {
        $validated = $request->validate([
            'whatsapp_number' => ['required', 'string', 'max:32'],
            'current_password' => ['required', 'string'],
        ]);

        $user = Auth::user();

        if ($user->whatsapp_verified_at !== null) {
            return response()->json([
                'message' => __('Your WhatsApp number is already verified. Change it in Settings → Notifications.'),
                'code' => 'whatsapp_already_verified',
            ], 422);
        }

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => __('The current password is incorrect.')]);
        }

        $number = OtpService::normalizeNumber($validated['whatsapp_number']);

        if (strlen($number) < 8 || strlen($number) > 15) {
            throw ValidationException::withMessages(['whatsapp_number' => __('Enter the WhatsApp number with its country code.')]);
        }

        if ($number === OtpService::normalizeNumber((string) $user->whatsapp_number)) {
            throw ValidationException::withMessages([
                'whatsapp_number' => __('The code was already sent to this number. Use “Resend code” to get a new one.'),
            ]);
        }

        $this->otpService->replaceUnverifiedNumber($user, $number);

        return response()->json([
            'message' => 'Verification code sent to the new number.',
            'whatsapp_number' => $this->mask($number),
            'whatsapp_number_full' => $number,
            'cooldown' => $this->otpService->cooldownRemaining($user),
            'ttl_minutes' => OtpService::ttlMinutes(),
        ]);
    }

    /** Show only the last 4 digits of the destination number. */
    private function mask(?string $number): ?string
    {
        if (! $number) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $number);

        return strlen($digits) <= 4 ? $digits : str_repeat('•', max(0, strlen($digits) - 4)).substr($digits, -4);
    }
}
