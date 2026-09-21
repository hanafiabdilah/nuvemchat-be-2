<?php

namespace App\Services\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * What stops somebody guessing their way into an account.
 *
 * Both sign-in endpoints — the dashboard's and the Back Office's — used to
 * accept an unlimited number of attempts. No throttle, no lockout, no delay,
 * and nothing written down when an attempt failed, so a credential-stuffing run
 * against the most privileged accounts on the platform would have left no trace
 * at all.
 *
 * ⚠️ Counted on FAILURE only, not per request. A throttle middleware counts
 * every call, so a busy office behind one address would lock itself out by
 * signing in successfully — which teaches everyone to route around the control.
 * The route keeps a coarse per-IP `throttle` on top of this as a flood stop;
 * this is the part that actually bounds guessing.
 *
 * Two counters, because they answer different questions:
 *
 *   - **per account + address** — is somebody working on this one account?
 *     Five tries buys a person who genuinely forgot their password enough room
 *     and leaves a guesser nowhere to go.
 *   - **per address** — is somebody spraying one password across many accounts?
 *     The first counter never sees that: each account is only tried once.
 *
 * Both decay over the same window rather than needing an unlock, because an
 * account a customer cannot get back into is its own outage, and support
 * unlocking accounts by hand is how lockout policies get switched off.
 */
final class LoginThrottle
{
    /** Failures against one account from one address before it stops answering. */
    private const ACCOUNT_ATTEMPTS = 5;

    /** Failures from one address, across every account, before the same. */
    private const ADDRESS_ATTEMPTS = 30;

    /** How long either counter takes to forget, in seconds. */
    private const DECAY = 900;

    public function __construct(
        private readonly string $surface,
    ) {}

    /** The dashboard sign-in. */
    public static function tenant(): self
    {
        return new self('tenant');
    }

    /** The Back Office sign-in. */
    public static function admin(): self
    {
        return new self('admin');
    }

    /**
     * Refuse early when either counter is spent.
     *
     * Thrown as a validation error on `email` so every client already renders
     * it — the two sign-in screens and the API both understand 422 without
     * anything being added to them.
     */
    public function assertNotLocked(Request $request, string $email): void
    {
        foreach ([
            [$this->accountKey($request, $email), self::ACCOUNT_ATTEMPTS],
            [$this->addressKey($request), self::ADDRESS_ATTEMPTS],
        ] as [$key, $attempts]) {
            if (! RateLimiter::tooManyAttempts($key, $attempts)) {
                continue;
            }

            $seconds = RateLimiter::availableIn($key);

            Log::warning('Sign-in blocked by throttle', [
                'surface' => $this->surface,
                'email' => $this->maskEmail($email),
                'ip' => $request->ip(),
                'retry_after' => $seconds,
            ]);

            throw ValidationException::withMessages([
                'email' => __('Too many sign-in attempts. Try again in :seconds seconds.', [
                    'seconds' => $seconds,
                ]),
            ])->status(429);
        }
    }

    /** Record a failed attempt against both counters. */
    public function recordFailure(Request $request, string $email): void
    {
        RateLimiter::hit($this->accountKey($request, $email), self::DECAY);
        RateLimiter::hit($this->addressKey($request), self::DECAY);

        // The e-mail is masked because this line is written for whoever is
        // watching for an attack, and they do not need the address to see one.
        Log::warning('Sign-in failed', [
            'surface' => $this->surface,
            'email' => $this->maskEmail($email),
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 200, ''),
        ]);
    }

    /** Forget the account counter after a real sign-in. */
    public function clear(Request $request, string $email): void
    {
        RateLimiter::clear($this->accountKey($request, $email));
    }

    /**
     * Keyed on account AND address together.
     *
     * On the account alone, anyone could lock a competitor's owner out of their
     * own workspace by failing five times against their address — a denial of
     * service handed out for free. Pairing it with the address means an attacker
     * can only lock out themselves.
     */
    private function accountKey(Request $request, string $email): string
    {
        return 'login:'.$this->surface.':'.Str::lower(trim($email)).'|'.$request->ip();
    }

    private function addressKey(Request $request): string
    {
        return 'login-ip:'.$this->surface.':'.$request->ip();
    }

    /** `ma••••••@example.com` — enough to recognise, not enough to harvest. */
    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', trim($email), 2), 2, null);

        if ($domain === null || $local === '') {
            return '***';
        }

        return Str::limit($local, 2, '').str_repeat('•', max(1, mb_strlen($local) - 2)).'@'.$domain;
    }
}
