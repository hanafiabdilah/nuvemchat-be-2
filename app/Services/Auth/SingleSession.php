<?php

namespace App\Services\Auth;

use App\Enums\Auth\SessionDevice;
use App\Events\SessionSuperseded;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * One computer and one phone per account: signing in ends the account's other
 * dashboard session of the same kind (App\Enums\Auth\SessionDevice), and
 * only that one. A computer and a phone can be signed in at the same time;
 * two computers, or two phones, cannot.
 *
 * Sessions from before the kind was recorded (`device_kind` null) count as
 * both, so the next sign-in of either kind ends them — what it always did.
 *
 * Four things make that more than `tokens()->delete()`:
 *
 * - **The kind is decided at sign-in and kept on the token**
 *   (`personal_access_tokens.device_kind`, with the label people read under
 *   Profile → Signed-in devices). Every sign-in goes through issue(), so no
 *   session is created without one.
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
     * Create a dashboard session for `$user`, marked with the kind and name of
     * the device it was created from.
     */
    public function issue(User $user, ?Request $request = null): NewAccessToken
    {
        $issued = $user->createToken(self::TOKEN_NAME);

        $issued->accessToken->forceFill([
            'device_kind' => SessionDevice::fromRequest($request)->value,
            'device_label' => self::deviceLabel($request?->userAgent()),
        ])->save();

        return $issued;
    }

    /**
     * The Pingly app has just registered for push on this session — proof that
     * it is a phone, whatever the sign-in's User-Agent said. Corrects the kind
     * (and fills a missing label) so the next computer sign-in does not end it.
     *
     * Deliberately not a claim(): a session re-filed as a phone after the fact
     * does not end the account's other phone. It only stops being mistaken
     * for the computer.
     */
    public static function markAsApp(int $tokenId, string $platform): void
    {
        $os = match ($platform) {
            'ios' => 'iOS',
            'android' => 'Android',
            default => null,
        };

        $token = PersonalAccessToken::query()
            ->whereKey($tokenId)
            ->where('name', self::TOKEN_NAME)
            ->first();

        if ($token === null) {
            return;
        }

        $token->forceFill([
            'device_kind' => SessionDevice::Phone->value,
            'device_label' => $token->device_label ?? implode(' · ', array_filter(['Pingly app', $os])),
        ]);

        if ($token->isDirty()) {
            $token->save();
        }
    }

    /**
     * The account's dashboard sessions that are still alive, newest activity
     * first. Impersonation tokens are not listed: they are a Back Office
     * operator's, and API keys and MCP connections are not sessions at all.
     *
     * @return Collection<int, \Laravel\Sanctum\PersonalAccessToken>
     */
    public static function activeSessions(User $user): Collection
    {
        $query = $user->tokens()->where('name', self::TOKEN_NAME);

        // Sanctum ends a token by age as well as by expires_at, without
        // deleting the row, so a dead session can still be in the table.
        $minutes = config('sanctum.expiration');

        if ($minutes) {
            $query->where('created_at', '>', now()->subMinutes((int) $minutes));
        }

        return $query
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByRaw('COALESCE(last_used_at, created_at) DESC')
            ->get();
    }

    /**
     * Make `$token` the account's only session of its kind.
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
            $kind = $token->accessToken->getAttribute('device_kind')
                ?? SessionDevice::fromRequest($request)->value;
            $at = now()->getTimestamp();
            $keep = (int) $token->accessToken->getKey();

            $ended = Cache::lock('single-session:'.$user->id, 10)->block(5, function () use ($user, $keep, $kind, $device, $at) {
                $others = $user->tokens()
                    ->where('name', self::TOKEN_NAME)
                    ->where('id', '!=', $keep)
                    // Same kind, or a session from before kinds were kept.
                    ->where(fn (Builder $q) => $q->where('device_kind', $kind)->orWhereNull('device_kind'))
                    ->get(['id', 'token']);

                if ($others->isEmpty()) {
                    return [];
                }

                $ttl = now()->addDays(max(1, (int) config('single_session.remember_days', 8)));

                // Remembered before it is deleted: a marker for a token that is
                // still valid is harmless, a deleted token nobody remembers is
                // the unexplained logout this exists to prevent.
                foreach ($others as $other) {
                    Cache::put(self::CACHE_PREFIX.$other->token, ['device' => $device, 'kind' => $kind, 'at' => $at], $ttl);
                }

                // Through the model, not a mass delete — the phone's push
                // registration hangs off the session by foreign key either way,
                // but anything listening for a token's deletion should hear it.
                $others->each->delete();

                return $others->modelKeys();
            });

            if ($ended !== []) {
                broadcast(new SessionSuperseded($user->id, $ended, $device, $kind, $at));

                Log::info('Sign-in ended the account\'s other session of the same kind', [
                    'user_id' => $user->id,
                    'tenant_id' => $user->tenant_id,
                    'ended' => count($ended),
                    'device' => $device,
                    'kind' => $kind,
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
     * @return array{device: ?string, kind: ?string, at: int}|null
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

        // `kind` is absent on markers written before the rule allowed one of
        // each, and the dashboard then says "another device".
        return is_array($found)
            ? ['device' => $found['device'] ?? null, 'kind' => $found['kind'] ?? null, 'at' => (int) ($found['at'] ?? 0)]
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
