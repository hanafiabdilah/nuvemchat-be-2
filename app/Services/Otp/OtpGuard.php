<?php

namespace App\Services\Otp;

use App\Exceptions\Auth\AuthRefusedException;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * How many wrong codes a verification will take, and what happens after.
 *
 * Every code allows three tries. The third wrong one spends the code — the
 * screen asks for a new one. The second code spent that way blocks the
 * verification for an hour, resend included, because two codes guessed through
 * is no longer somebody mistyping: it is somebody without the phone.
 *
 * Counted per *identity*, not per code row: the registration check is keyed on
 * the signed-in user, but password recovery is public, and keying it on the
 * account would make it an oracle — a registered address would start saying
 * "request a new code" and then "blocked", an unknown one "incorrect" forever.
 * So recovery is keyed on the e-mail typed, and an address with no account
 * behind it walks through the very same sequence (see the phantom* methods,
 * used by PasswordResetController): issued, wrong, spent, blocked.
 *
 * In the cache, not in `otps`: the block has to outlive the code rows it was
 * earned on, and an e-mail with no account has no rows to count.
 */
final class OtpGuard
{
    /** Wrong codes one code takes before it is spent. */
    public const ATTEMPTS_PER_CODE = 3;

    /** Spent codes before verification is blocked. */
    public const SPENT_CODES_BEFORE_BLOCK = 2;

    /** How long the block lasts. */
    public const BLOCK_SECONDS = 3600;

    /**
     * How long a spent code is remembered toward the block. A day: long enough
     * that requesting codes slowly is not a way around it, short enough that a
     * customer who fumbled once last week starts clean.
     */
    private const STRIKE_MEMORY_SECONDS = 86400;

    /** Whose verification this is, for the counters below. */
    public static function identityFor(User $user, string $purpose): string
    {
        return $purpose === OtpService::PURPOSE_PASSWORD_RESET
            ? self::identityForEmail((string) $user->email)
            : 'user:'.$user->id;
    }

    /** Recovery is keyed on the address typed, account or not. */
    public static function identityForEmail(string $email): string
    {
        return 'email:'.hash('sha256', Str::lower(trim($email)));
    }

    /** Refuse while blocked. */
    public function assertNotBlocked(string $purpose, string $identity): void
    {
        $seconds = $this->blockedFor($purpose, $identity);

        if ($seconds > 0) {
            throw $this->blockedException($seconds);
        }
    }

    /** Seconds left on the block, 0 when there is none. */
    public function blockedFor(string $purpose, string $identity): int
    {
        $until = (int) Cache::get($this->key('block', $purpose, $identity), 0);

        return max(0, $until - now()->timestamp);
    }

    /** A fresh code went out: it gets its own three tries. */
    public function codeIssued(string $purpose, string $identity): void
    {
        Cache::forget($this->key('attempts', $purpose, $identity));
    }

    /** The current code has taken all the tries it allows. */
    public function codeSpent(string $purpose, string $identity): bool
    {
        return (int) Cache::get($this->key('attempts', $purpose, $identity), 0) >= self::ATTEMPTS_PER_CODE;
    }

    /** Refuse a code that is already spent, without counting anything. */
    public function assertCodeNotSpent(string $purpose, string $identity): void
    {
        if ($this->codeSpent($purpose, $identity)) {
            throw $this->spentException();
        }
    }

    /**
     * A wrong code. Always throws: attempts left, the code spent, or — on the
     * second spent code — the block.
     */
    public function fail(string $purpose, string $identity): never
    {
        $attemptsKey = $this->key('attempts', $purpose, $identity);
        Cache::add($attemptsKey, 0, self::STRIKE_MEMORY_SECONDS);
        $used = (int) Cache::increment($attemptsKey);

        if ($used < self::ATTEMPTS_PER_CODE) {
            $left = self::ATTEMPTS_PER_CODE - $used;

            throw new AuthRefusedException(
                "Incorrect code. {$left} attempt(s) left for this code.",
                'otp_incorrect',
                'code',
                422,
                ['attempts_left' => $left],
            );
        }

        $strikesKey = $this->key('strikes', $purpose, $identity);
        Cache::add($strikesKey, 0, self::STRIKE_MEMORY_SECONDS);
        $strikes = (int) Cache::increment($strikesKey);

        if ($strikes < self::SPENT_CODES_BEFORE_BLOCK) {
            throw $this->spentException();
        }

        Cache::put($this->key('block', $purpose, $identity), now()->addSeconds(self::BLOCK_SECONDS)->timestamp, self::BLOCK_SECONDS);
        Cache::forget($strikesKey);
        Cache::forget($attemptsKey);

        Log::warning('Verification blocked after too many wrong codes', [
            'purpose' => $purpose,
            // The identity is a user id or a hash of the address — never the address.
            'identity' => $identity,
        ]);

        throw $this->blockedException(self::BLOCK_SECONDS);
    }

    /** The right code: everything this identity was carrying is forgiven. */
    public function succeeded(string $purpose, string $identity): void
    {
        Cache::forget($this->key('attempts', $purpose, $identity));
        Cache::forget($this->key('strikes', $purpose, $identity));
    }

    // ── An address with no account behind it ─────────────────────────────────

    /**
     * Recovery asked for an address with no account behind it. Recorded as if a
     * code had gone out — on the same resend cooldown — so the next steps
     * answer exactly as they would for an account.
     */
    public function phantomIssued(string $purpose, string $identity): void
    {
        if (! Cache::add($this->key('cooldown', $purpose, $identity), 1, OtpService::resendCooldownSeconds())) {
            return;
        }

        Cache::put($this->key('issued', $purpose, $identity), now()->timestamp, self::STRIKE_MEMORY_SECONDS);
        $this->codeIssued($purpose, $identity);
    }

    /**
     * A code checked against an address with no account: never right, and
     * refused in the same order OtpService refuses a real one — nothing
     * pending, expired, spent, then a wrong attempt.
     */
    public function phantomCheck(string $purpose, string $identity): never
    {
        $this->assertNotBlocked($purpose, $identity);

        $issuedAt = Cache::get($this->key('issued', $purpose, $identity));

        if ($issuedAt === null) {
            throw self::missingException();
        }

        if (now()->timestamp - (int) $issuedAt > OtpService::ttlMinutes() * 60) {
            throw self::expiredException();
        }

        $this->assertCodeNotSpent($purpose, $identity);
        $this->fail($purpose, $identity);
    }

    // ── Refusals ──────────────────────────────────────────────────────────────

    public static function missingException(): AuthRefusedException
    {
        return new AuthRefusedException('No pending verification. Request a new code.', 'otp_missing', 'code');
    }

    public static function expiredException(): AuthRefusedException
    {
        return new AuthRefusedException('This code has expired. Request a new one.', 'otp_expired', 'code');
    }

    private function spentException(): AuthRefusedException
    {
        return new AuthRefusedException(
            'You used all the attempts for this code. Request a new code.',
            'otp_attempts_exhausted',
            'code',
        );
    }

    private function blockedException(int $seconds): AuthRefusedException
    {
        return AuthRefusedException::blocked(
            'Too many incorrect codes. For your security, verification is blocked for 1 hour. Try again later or contact our support team.',
            'otp_blocked',
            'code',
            $seconds,
        );
    }

    private function key(string $what, string $purpose, string $identity): string
    {
        return "otp:{$what}:{$purpose}:{$identity}";
    }
}
