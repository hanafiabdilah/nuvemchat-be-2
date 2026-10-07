<?php

namespace App\Services\Auth;

use App\Events\SessionSuperseded;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\NewAccessToken;

/**
 * One sign-in per account: signing in ends the account's other dashboard
 * sessions.
 *
 * Three things make that more than `tokens()->delete()`:
 *
 * - **Only sign-ins are sessions.** Tokens are told apart by name. An
 *   impersonation token belongs to a Back Office operator looking at the
 *   workspace: it must not throw the customer out, and the customer signing in
 *   must not throw the operator out either.
 *
 * - **The device that lost has to be told, twice.** Deleting the token is
 *   silent: the old dashboard keeps its socket and looks alive until the next
 *   request fails. So an event goes out for the tab that is open, and the ended
 *   token is remembered for the tab that is not — its next request gets a 401
 *   that says why (see bootstrap/app.php) instead of a bare "Unauthenticated".
 *
 * - **Two sign-ins at once must not end each other.** Each would create its
 *   token, then delete "every other one" — including the other's. The lock
 *   makes them take turns, so the later one wins and the earlier one is ended
 *   like any other old session.
 */
class SingleSession
{
    /** The name AuthController gives a dashboard sign-in's token. */
    public const TOKEN_NAME = 'auth_token';

    private const CACHE_PREFIX = 'session-superseded:';

    public static function enabled(): bool
    {
        return (bool) config('single_session.enabled', true);
    }

    /**
     * Make `$token` the account's only session.
     *
     * Never throws: by the time this runs the person has proved who they are,
     * and a cache or broadcast hiccup is not a reason to refuse them.
     */
    public function claim(User $user, NewAccessToken $token, ?Request $request = null): void
    {
        if (! self::enabled()) {
            return;
        }

        try {
            $device = self::deviceLabel($request?->userAgent());
            $at = now()->getTimestamp();
            $keep = (int) $token->accessToken->getKey();

            $ended = Cache::lock('single-session:'.$user->id, 10)->block(5, function () use ($user, $keep, $device, $at) {
                $others = $user->tokens()
                    ->where('name', self::TOKEN_NAME)
                    ->where('id', '!=', $keep)
                    ->get(['id', 'token']);

                if ($others->isEmpty()) {
                    return [];
                }

                $ttl = now()->addDays(max(1, (int) config('single_session.remember_days', 8)));

                // Remembered before it is deleted: a marker for a token that is
                // still valid is harmless, a deleted token nobody remembers is
                // the unexplained logout this exists to prevent.
                foreach ($others as $other) {
                    Cache::put(self::CACHE_PREFIX.$other->token, ['device' => $device, 'at' => $at], $ttl);
                }

                // Through the model, not a mass delete — the phone's push
                // registration hangs off the session by foreign key either way,
                // but anything listening for a token's deletion should hear it.
                $others->each->delete();

                return $others->modelKeys();
            });

            if ($ended !== []) {
                broadcast(new SessionSuperseded($user->id, $ended, $device, $at));

                Log::info('Sign-in ended the account\'s other sessions', [
                    'user_id' => $user->id,
                    'tenant_id' => $user->tenant_id,
                    'ended' => count($ended),
                    'device' => $device,
                ]);
            }
        } catch (\Throwable $th) {
            Log::warning('SingleSession: could not end the other sessions', [
                'user_id' => $user->id,
                'error' => $th->getMessage(),
            ]);
        }
    }

    /**
     * Was this bearer token ended by a later sign-in?
     *
     * Asked only once a request has already failed authentication, so it costs
     * nothing on the way in. Knowing the answer requires holding the token.
     *
     * @return array{device: ?string, at: int}|null
     */
    public static function supersededBy(?string $bearer): ?array
    {
        if ($bearer === null || $bearer === '') {
            return null;
        }

        // Sanctum's plain token is "{id}|{secret}" and it stores sha256(secret).
        $secret = str_contains($bearer, '|') ? explode('|', $bearer, 2)[1] : $bearer;

        try {
            $found = Cache::get(self::CACHE_PREFIX.hash('sha256', $secret));
        } catch (\Throwable) {
            return null;
        }

        return is_array($found)
            ? ['device' => $found['device'] ?? null, 'at' => (int) ($found['at'] ?? 0)]
            : null;
    }

    /**
     * "Chrome · Windows" — enough for somebody to recognise their own other
     * device, or to realise it is not theirs. Deliberately coarse: this is a
     * sentence on a login screen, not device fingerprinting.
     */
    public static function deviceLabel(?string $userAgent): ?string
    {
        $ua = (string) $userAgent;

        if ($ua === '') {
            return null;
        }

        $os = match (true) {
            (bool) preg_match('/iPhone|iPad|iPod/i', $ua) => 'iOS',
            stripos($ua, 'Android') !== false => 'Android',
            stripos($ua, 'Windows') !== false => 'Windows',
            (bool) preg_match('/Macintosh|Mac OS X/i', $ua) => 'macOS',
            stripos($ua, 'CrOS') !== false => 'ChromeOS',
            stripos($ua, 'Linux') !== false => 'Linux',
            default => null,
        };

        // Order matters: Edge and Opera both announce Chrome, Chrome announces Safari.
        $browser = match (true) {
            stripos($ua, 'PinglyApp/') !== false => 'Pingly app',
            (bool) preg_match('/Edg(e|A|iOS)?\//', $ua) => 'Edge',
            (bool) preg_match('/OPR\/|Opera/', $ua) => 'Opera',
            (bool) preg_match('/Firefox\/|FxiOS\//', $ua) => 'Firefox',
            (bool) preg_match('/Chrome\/|CriOS\//', $ua) => 'Chrome',
            stripos($ua, 'Safari/') !== false => 'Safari',
            default => null,
        };

        $label = implode(' · ', array_filter([$browser, $os]));

        return $label !== '' ? $label : null;
    }
}
