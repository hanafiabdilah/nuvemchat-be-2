<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Notification\WhatsappAlerts;
use App\Services\Otp\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * The number WhatsApp alerts go to — the account's own verified WhatsApp —
 * and a test message to it (Settings → Notifications).
 *
 * Changing the number costs the current password: it is also the number a
 * password reset is sent to, so whoever controls it controls the account.
 */
class WhatsappAlertController extends Controller
{
    public function __construct(protected OtpService $otp) {}

    /** Send a code to the number the person wants to use. Nothing changes until it is confirmed. */
    public function requestNumber(Request $request)
    {
        $validated = $request->validate([
            'whatsapp_number' => ['required', 'string', 'max:32'],
            'current_password' => ['required', 'string'],
        ]);

        $user = Auth::user();

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => __('The current password is incorrect.')]);
        }

        $number = OtpService::normalizeNumber($validated['whatsapp_number']);

        if (strlen($number) < 8 || strlen($number) > 15) {
            throw ValidationException::withMessages(['whatsapp_number' => __('Enter the WhatsApp number with its country code.')]);
        }

        if ($number === OtpService::normalizeNumber((string) $user->whatsapp_number) && $user->whatsapp_verified_at !== null) {
            throw ValidationException::withMessages(['whatsapp_number' => __('This is already your verified number.')]);
        }

        $this->otp->request($user, OtpService::PURPOSE_WHATSAPP_CHANGE, $number);

        return response()->json([
            'cooldown' => $this->otp->cooldownRemaining($user, OtpService::PURPOSE_WHATSAPP_CHANGE),
            'ttl_minutes' => OtpService::ttlMinutes(),
        ]);
    }

    /** Confirm the code: the number it went to becomes the account's number. */
    public function verifyNumber(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'min:4', 'max:10'],
        ]);

        $user = Auth::user();

        $this->otp->verify($user, $validated['code'], OtpService::PURPOSE_WHATSAPP_CHANGE);

        $user->refresh();

        return response()->json([
            'whatsapp_number' => $user->whatsapp_number,
            'whatsapp_verified' => $user->whatsapp_verified_at !== null,
        ]);
    }

    /** A first alert, so the person sees one arrive before relying on them. */
    public function test()
    {
        $user = Auth::user();

        if ($user->whatsapp_verified_at === null || trim((string) $user->whatsapp_number) === '') {
            return response()->json([
                'message' => __('Add and confirm your WhatsApp number first.'),
                'code' => 'whatsapp_number_missing',
            ], 422);
        }

        if (! WhatsappAlerts::test($user)) {
            return response()->json([
                'message' => __('WhatsApp alerts are switched off on the platform right now.'),
                'code' => 'alerts_unavailable',
            ], 422);
        }

        return response()->json(['sent' => true]);
    }
}
