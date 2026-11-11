<?php

namespace App\Services\Otp;

use App\Enums\Notification\NotificationType;
use App\Exceptions\Auth\AuthRefusedException;
use App\Models\Otp;
use App\Models\User;
use App\Models\WhatsappMessageLog;
use App\Services\Notification\NotificationConfig;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Issues and verifies one-time passwords sent to a user's WhatsApp number.
 * Codes are persisted (so the Back Office can inspect them) and delivered over the
 * configured WhatsApp notification provider (Pingly by default). Delivery is
 * best-effort: the code is always stored even when no provider is configured.
 *
 * Purposes are first-class: each one maps to its own NotificationType (and therefore
 * its own admin-editable message body) and to its own post-verification side effects.
 * Codes never cross purposes — a signup code cannot be replayed to reset a password.
 */
class OtpService
{
    public const PURPOSE_WHATSAPP = 'whatsapp_verification';
    public const PURPOSE_PASSWORD_RESET = 'password_reset';

    /**
     * Replacing (or adding) the account's WhatsApp number. The code goes to the
     * NEW number, and the account keeps the old one until it is confirmed: an
     * unverified number on the account locks its owner out of the app
     * (EnsureWhatsAppVerified), and a typo must never be able to do that.
     */
    public const PURPOSE_WHATSAPP_CHANGE = 'whatsapp_change';

    private const CODE_LENGTH = 6;

    /**
     * Five minutes: a code sent to a phone is typed within seconds, and every
     * minute it stays valid is a minute somebody who saw the screen can use it.
     */
    private const TTL_MINUTES = 5;

    private const RESEND_COOLDOWN_SECONDS = 60;

    /**
     * How many wrong codes are allowed — per code, and before verification is
     * blocked — lives in OtpGuard, shared with the password recovery steps for
     * addresses that have no account.
     */
    public function __construct(
        protected NotificationService $notifications,
        protected OtpGuard $guard,
    ) {}

    /**
     * Normalize a phone number to bare digits (E.164 without the leading +),
     * which is what all WhatsApp providers expect as the recipient.
     */
    public static function normalizeNumber(string $number): string
    {
        return preg_replace('/\D+/', '', $number) ?? '';
    }

    /** The notification event (and therefore the message template) used by a purpose. */
    public static function notificationTypeFor(string $purpose): NotificationType
    {
        return match ($purpose) {
            self::PURPOSE_PASSWORD_RESET => NotificationType::PasswordResetOtp,
            default => NotificationType::WhatsappOtp,
        };
    }

    /** How long a freshly issued code stays valid, in minutes. */
    public static function ttlMinutes(): int
    {
        return self::TTL_MINUTES;
    }

    /**
     * The full resend window.
     *
     * Public because the recovery endpoint has to answer with a figure that is
     * the same for everyone — the *remaining* seconds differ between an account
     * that exists and one that does not, which is an answer to a question
     * nobody asked it.
     */
    public static function resendCooldownSeconds(): int
    {
        return self::RESEND_COOLDOWN_SECONDS;
    }

    /**
     * Generate, persist and dispatch a fresh OTP for the user's WhatsApp number.
     * Enforces a resend cooldown. Returns the created Otp.
     */
    public function request(User $user, string $purpose = self::PURPOSE_WHATSAPP, ?string $destination = null): Otp
    {
        // Only a number change sends somewhere other than the number on file.
        $number = self::normalizeNumber((string) ($purpose === self::PURPOSE_WHATSAPP_CHANGE ? $destination : $user->whatsapp_number));
        $identity = OtpGuard::identityFor($user, $purpose);

        // A blocked verification cannot be restarted by asking for a new code:
        // that is the very thing the block is there to stop.
        $this->guard->assertNotBlocked($purpose, $identity);

        // Verifying a number needs a number. A password reset does not: the
        // code is still issued and stored — the Back Office's OTP list is how
        // support reads it out to a customer whose account predates the
        // WhatsApp requirement — only its delivery is skipped (dispatch()).
        if ($number === '' && $purpose !== self::PURPOSE_PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'whatsapp_number' => 'No WhatsApp number on file to verify.',
            ]);
        }

        // A code that no longer works does not hold the next one back. In the
        // normal course that changes nothing (a code outlives the cooldown by
        // minutes); it is what lets a corrected number get its code at once,
        // because replaceUnverifiedNumber() expires the code sent to the wrong one.
        $recent = Otp::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('verified_at')
            ->where('expires_at', '>', now())
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

        $this->guard->codeIssued($purpose, $identity);

        $this->dispatch($otp, $user);

        return $otp;
    }

    /**
     * Swap a number that was never verified for the one the person meant, and
     * send the signup code there.
     *
     * Only for an unverified number: until the code is confirmed nothing has
     * been proven about the old one, and the account cannot get past the
     * verification screen with it (EnsureWhatsAppVerified) — so the number
     * typed at signup is replaced outright. A verified number is changed with
     * PURPOSE_WHATSAPP_CHANGE instead, which keeps the old one until the new
     * one is confirmed.
     *
     * The codes already sent to the old number are expired, not left pending:
     * the newest pending code is the one verify() checks, and the number it was
     * sent to is the number verification stamps on the account — so a code
     * still alive on the wrong phone could put that phone back.
     *
     * A verification block still holds: a new number is not a new allowance of
     * guesses.
     */
    public function replaceUnverifiedNumber(User $user, string $number): Otp
    {
        $number = self::normalizeNumber($number);

        $this->guard->assertNotBlocked(self::PURPOSE_WHATSAPP, OtpGuard::identityFor($user, self::PURPOSE_WHATSAPP));

        $previous = self::normalizeNumber((string) $user->whatsapp_number);

        DB::transaction(function () use ($user, $number) {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->first();

            // Checked again under the lock: a code confirmed in another tab a
            // moment ago means there is no unverified number left to replace.
            if ($locked === null || $locked->whatsapp_verified_at !== null) {
                throw new AuthRefusedException(
                    'Your WhatsApp number is already verified. Change it in Settings → Notifications.',
                    'whatsapp_already_verified',
                    'whatsapp_number',
                );
            }

            $locked->forceFill(['whatsapp_number' => $number])->save();

            Otp::where('user_id', $user->id)
                ->where('purpose', self::PURPOSE_WHATSAPP)
                ->whereNull('verified_at')
                ->where('expires_at', '>', now())
                ->update(['expires_at' => now()]);
        });

        $user->forceFill(['whatsapp_number' => $number]);
        $user->syncOriginalAttribute('whatsapp_number');

        // Ids and the last digits only: the log outlives the account's interest
        // in either number.
        Log::info('OtpService: unverified WhatsApp number replaced', [
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'from_last4' => substr($previous, -4),
            'to_last4' => substr($number, -4),
        ]);

        return $this->request($user, self::PURPOSE_WHATSAPP);
    }

    /**
     * Validate a submitted code WITHOUT consuming it, so a multi-step flow can gate
     * the next screen on a correct code and still consume it at the final step
     * (password reset: code screen → new-password screen). Failed attempts still count.
     */
    public function check(User $user, string $code, string $purpose = self::PURPOSE_WHATSAPP): Otp
    {
        return $this->pendingOtp($user, $code, $purpose);
    }

    /**
     * Verify a submitted code against the newest unverified OTP for the user and
     * consume it. On success, runs the purpose's side effects.
     */
    public function verify(User $user, string $code, string $purpose = self::PURPOSE_WHATSAPP): void
    {
        $otp = $this->pendingOtp($user, $code, $purpose);

        $otp->forceFill(['verified_at' => now()])->save();

        if ($purpose === self::PURPOSE_WHATSAPP) {
            $this->completeWhatsappVerification($user, $otp);
        }

        if ($purpose === self::PURPOSE_WHATSAPP_CHANGE) {
            // The number the code went to is the number proven, whatever the
            // account held before.
            $user->forceFill([
                'whatsapp_number' => $otp->whatsapp_number,
                'whatsapp_verified_at' => now(),
            ])->save();
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

    /** Seconds left on a verification block, 0 when there is none. */
    public function blockedFor(User $user, string $purpose = self::PURPOSE_WHATSAPP): int
    {
        return $this->guard->blockedFor($purpose, OtpGuard::identityFor($user, $purpose));
    }

    /**
     * The newest pending OTP for this user+purpose, once the submitted code has been
     * checked against it. Throws an AuthRefusedException (keyed 'code') otherwise:
     * a wrong code burns an attempt, the third spends the code, and the second
     * spent code blocks verification for an hour (OtpGuard).
     */
    private function pendingOtp(User $user, string $code, string $purpose): Otp
    {
        $identity = OtpGuard::identityFor($user, $purpose);

        $this->guard->assertNotBlocked($purpose, $identity);

        $otp = Otp::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('verified_at')
            ->latest('id')
            ->first();

        if (! $otp) {
            throw OtpGuard::missingException();
        }

        if ($otp->isExpired()) {
            throw OtpGuard::expiredException();
        }

        $this->guard->assertCodeNotSpent($purpose, $identity);

        if (! hash_equals($otp->code, trim($code))) {
            // Kept on the row as well, so the Back Office's OTP list shows it.
            $otp->increment('attempts');
            $this->guard->fail($purpose, $identity);
        }

        $this->guard->succeeded($purpose, $identity);

        return $otp;
    }

    /** Stamp the user's number as confirmed and welcome them the first time round. */
    private function completeWhatsappVerification(User $user, Otp $otp): void
    {
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
     * Hand the OTP to NotificationService, which renders the (admin-overridable)
     * template for this purpose and queues delivery off the request path — a slow or
     * unreachable provider must never block registration, resend or password recovery.
     * OTP events are required, so they are exempt from the notification toggles.
     */
    private function dispatch(Otp $otp, User $user): void
    {
        if ($otp->whatsapp_number === '') {
            // Nowhere to send it. Written to the WhatsApp log anyway, so that
            // page says why a code in the OTP list never reached anybody. The
            // body is left out: with no recipient it is just the code again.
            WhatsappMessageLog::record(
                NotificationConfig::provider(),
                '',
                self::notificationTypeFor($otp->purpose)->logType(),
                null,
                WhatsappMessageLog::STATUS_FAILED,
                'no WhatsApp number on file',
                $user->id,
            );

            return;
        }

        $this->notifications->send(self::notificationTypeFor($otp->purpose), $otp->whatsapp_number, [
            'name' => $user->name,
            'code' => $otp->code,
            'ttl' => (string) self::TTL_MINUTES,
        ], $user->id);
    }
}
