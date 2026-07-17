<?php

namespace App\Services\Otp;

use App\Enums\Notification\NotificationType;
use App\Models\Otp;
use App\Models\User;
use App\Services\Notification\NotificationService;
use Illuminate\Validation\ValidationException;

/**
 * Issues and verifies one-time passwords used to confirm a user's WhatsApp number.
 * Codes are persisted (so the Back Office can inspect them) and delivered over the
 * configured WhatsApp notification provider (Pingly by default). Delivery is
 * best-effort: the code is always stored even when no provider is configured.
 */
class OtpService
{
    public const PURPOSE_WHATSAPP = 'whatsapp_verification';

    private const CODE_LENGTH = 6;
    private const TTL_MINUTES = 10;
    private const RESEND_COOLDOWN_SECONDS = 60;
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        protected NotificationService $notifications,
    ) {}

    /**
     * Normalize a phone number to bare digits (E.164 without the leading +),
     * which is what all WhatsApp providers expect as the recipient.
     */
    public static function normalizeNumber(string $number): string
    {
        return preg_replace('/\D+/', '', $number) ?? '';
    }

    /**
     * Generate, persist and dispatch a fresh OTP for the user's WhatsApp number.
     * Enforces a resend cooldown. Returns the created Otp.
     */
    public function request(User $user, string $purpose = self::PURPOSE_WHATSAPP): Otp
    {
        $number = self::normalizeNumber((string) $user->whatsapp_number);

        if ($number === '') {
            throw ValidationException::withMessages([
                'whatsapp_number' => 'No WhatsApp number on file to verify.',
            ]);
        }

        $recent = Otp::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('verified_at')
            ->latest('id')
            ->first();

        if ($recent && $recent->created_at->diffInSeconds(now()) < self::RESEND_COOLDOWN_SECONDS) {
            $wait = self::RESEND_COOLDOWN_SECONDS - $recent->created_at->diffInSeconds(now());
            throw ValidationException::withMessages([
                'code' => "Please wait {$wait}s before requesting a new code.",
            ]);
        }

        $code = str_pad((string) random_int(0, 999999), self::CODE_LENGTH, '0', STR_PAD_LEFT);

        $otp = Otp::create([
            'user_id' => $user->id,
            'whatsapp_number' => $number,
            'code' => $code,
            'purpose' => $purpose,
            'attempts' => 0,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        $this->dispatch($otp, $user);

        return $otp;
    }

    /**
     * Verify a submitted code against the newest unverified OTP for the user.
     * On success, stamps the OTP and the user's whatsapp_verified_at.
     */
    public function verify(User $user, string $code, string $purpose = self::PURPOSE_WHATSAPP): void
    {
        $otp = Otp::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('verified_at')
            ->latest('id')
            ->first();

        if (! $otp) {
            throw ValidationException::withMessages(['code' => 'No pending verification. Request a new code.']);
        }

        if ($otp->isExpired()) {
            throw ValidationException::withMessages(['code' => 'This code has expired. Request a new one.']);
        }

        if ($otp->attempts >= self::MAX_ATTEMPTS) {
            throw ValidationException::withMessages(['code' => 'Too many attempts. Request a new code.']);
        }

        if (! hash_equals($otp->code, trim($code))) {
            $otp->increment('attempts');
            throw ValidationException::withMessages(['code' => 'Incorrect code. Please try again.']);
        }

        $otp->forceFill(['verified_at' => now()])->save();

        // The welcome rides on the first successful verification rather than on
        // registration: until the code is confirmed the number is unproven, and a
        // mistyped one would otherwise get a second unsolicited message.
        $firstVerification = $user->whatsapp_verified_at === null;

        $user->forceFill([
            'whatsapp_verified_at' => now(),
            'whatsapp_number' => $otp->whatsapp_number,
        ])->save();

        if ($firstVerification) {
            $this->notifications->send(NotificationType::WelcomeRegistration, $user->whatsapp_number, [
                'name' => $user->name,
            ], $user->id);
        }
    }

    /**
     * Seconds the user must wait before another code can be requested (0 if none).
     */
    public function cooldownRemaining(User $user, string $purpose = self::PURPOSE_WHATSAPP): int
    {
        $recent = Otp::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->latest('id')
            ->first();

        if (! $recent) return 0;

        $elapsed = $recent->created_at->diffInSeconds(now());

        return max(0, self::RESEND_COOLDOWN_SECONDS - $elapsed);
    }

    /**
     * Hand the OTP to NotificationService, which renders the (admin-overridable)
     * template and queues delivery off the request path — a slow or unreachable
     * provider must never block registration or resend. WhatsappOtp is a required
     * event, so it is exempt from the notification toggles.
     */
    private function dispatch(Otp $otp, User $user): void
    {
        $this->notifications->send(NotificationType::WhatsappOtp, $otp->whatsapp_number, [
            'code' => $otp->code,
            'ttl' => (string) self::TTL_MINUTES,
        ], $user->id);
    }
}
