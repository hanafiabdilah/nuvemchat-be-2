<?php

namespace App\Services\Webhook;

use App\Models\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * What makes a delivery to /webhook/chat/{id} genuine.
 *
 * That endpoint is the inbound path for Telegram and WhatsApp API Way, and it
 * used to accept anything: no signature, no token, and a connection id that is
 * a plain auto-increment. Anyone able to count could post a message into any
 * workspace's inbox, impersonate a customer who already exists there, and set
 * the flow engine running on the tenant's own prepaid balance.
 *
 * Each connection now carries a secret of its own, and a delivery has to
 * present it. Two ways in, because the two channels can carry it differently:
 *
 *  - **Telegram** takes `secret_token` on setWebhook and returns it on every
 *    delivery as `X-Telegram-Bot-Api-Secret-Token`. A header, so the secret
 *    never reaches a proxy access log.
 *  - **API Way** has no such field — the core only stores a URL — so the
 *    secret rides as the last path segment. That does put it in Caddy's log,
 *    which is the reason it is per connection and rotatable rather than one
 *    platform-wide value.
 *
 * Either carrier is accepted for either channel. The proof is knowing the
 * secret; where it was written is the sender's constraint, not ours.
 *
 * ⚠️ A connection with NO secret stored is still let through, and that is
 * deliberate but temporary. The webhooks of every live connection are already
 * registered upstream without one; refusing them the moment this deploys would
 * drop real customer messages for every Telegram and API Way workspace at once.
 * The sequence is: deploy → `php artisan webhooks:secure-chat` (registers the
 * secret upstream, then stores it) → confirm the Health page reads zero
 * unsecured → set WEBHOOK_CHAT_STRICT=true. Until then every unauthenticated
 * delivery is logged, so the gap is visible rather than assumed.
 *
 * ⚠️ The fallback is per connection, never global: once a connection HAS a
 * secret, a delivery without it is refused outright. So the window closes one
 * connection at a time as the command works through them, instead of staying
 * open until somebody remembers to flip a flag.
 */
final class ChatWebhookSecret
{
    /**
     * Where the secret lives on the connection.
     *
     * Inside `credentials` rather than in a column of its own so it travels
     * with the rest of a channel's credentials — and so ConnectionResource,
     * which already strips channel secrets, is the single place that decides
     * what reaches the browser. It strips this key for every channel.
     */
    public const CREDENTIAL_KEY = 'webhook_secret';

    /** Telegram echoes back whatever `secret_token` setWebhook was given. */
    public const TELEGRAM_HEADER = 'X-Telegram-Bot-Api-Secret-Token';

    /**
     * 48 characters of `Str::random`, which is `[A-Za-z0-9]`.
     *
     * The alphabet is the binding constraint, not the length: Telegram only
     * accepts `A-Za-z0-9_-` for `secret_token`, and the API Way core carries
     * the value as a path segment, where anything needing escaping would come
     * back to us decoded differently than it left.
     */
    public static function generate(): string
    {
        return Str::random(48);
    }

    /** The connection's secret, or null when it has not been secured yet. */
    public static function of(Connection $connection): ?string
    {
        $secret = $connection->credentials[self::CREDENTIAL_KEY] ?? null;

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    /**
     * Persist a secret, keeping the rest of the credentials intact.
     *
     * Callers store only after the upstream registration that carries the
     * secret has been accepted. Storing first would mean that a failed
     * registration leaves us expecting a token the sender was never told
     * about — which is the one way this change could drop real messages.
     */
    public static function store(Connection $connection, string $secret): void
    {
        $connection->update([
            'credentials' => array_merge($connection->credentials ?? [], [
                self::CREDENTIAL_KEY => $secret,
            ]),
        ]);

        $connection->refresh();
    }

    /** Whether this delivery proves knowledge of the connection's secret. */
    public static function presented(Request $request, Connection $connection, ?string $routeToken): bool
    {
        $secret = self::of($connection);

        if ($secret === null) {
            return false;
        }

        $offered = array_filter([
            $request->header(self::TELEGRAM_HEADER),
            $routeToken,
        ], static fn ($value) => is_string($value) && $value !== '');

        foreach ($offered as $candidate) {
            if (hash_equals($secret, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this delivery may be processed.
     *
     * Three outcomes, and the middle one is the whole point: a connection that
     * has been secured refuses anything unsigned, while one that has not been
     * reached yet is still served — loudly.
     */
    public static function authorizes(Request $request, Connection $connection, ?string $routeToken): bool
    {
        if (self::presented($request, $connection, $routeToken)) {
            return true;
        }

        if (self::of($connection) !== null) {
            Log::warning('Chat webhook rejected: wrong or missing secret', [
                'connection_id' => $connection->id,
                'channel' => $connection->channel->value,
                'ip' => $request->ip(),
                'carried_token' => $routeToken !== null,
                'carried_header' => $request->hasHeader(self::TELEGRAM_HEADER),
            ]);

            return false;
        }

        if (self::strict()) {
            Log::warning('Chat webhook rejected: connection has no secret and strict mode is on', [
                'connection_id' => $connection->id,
                'channel' => $connection->channel->value,
                'ip' => $request->ip(),
            ]);

            return false;
        }

        Log::warning('Chat webhook accepted without a secret — run webhooks:secure-chat', [
            'connection_id' => $connection->id,
            'channel' => $connection->channel->value,
            'ip' => $request->ip(),
        ]);

        return true;
    }

    /**
     * Whether an unsecured connection is refused rather than served.
     *
     * Off by default so that deploying this code, on its own, changes nothing
     * for traffic that is already flowing. Turned on once the Health page
     * reports no connections left to secure.
     */
    public static function strict(): bool
    {
        return (bool) config('services.webhooks.chat_strict', false);
    }
}
