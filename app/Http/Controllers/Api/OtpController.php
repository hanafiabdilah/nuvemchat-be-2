<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Otp\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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
            'verified' => $user->whatsapp_verified_at !== null,
            'cooldown' => $this->otpService->cooldownRemaining($user),
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

    /** Show only the last 4 digits of the destination number. */
    private function mask(?string $number): ?string
    {
        if (! $number) return null;
        $digits = preg_replace('/\D+/', '', $number);

        return strlen($digits) <= 4 ? $digits : str_repeat('•', max(0, strlen($digits) - 4)) . substr($digits, -4);
    }
}
