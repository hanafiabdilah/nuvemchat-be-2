<?php

namespace App\Services\Connection;

use App\Services\Webhook\ChatWebhookSecret;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Which parts of `connections.credentials` are secrets, and how they are kept.
 *
 * This column holds the most valuable thing the platform stores on a customer's
 * behalf: the token that sends WhatsApp messages as their business, the bot
 * token that *is* total control of their Telegram bot, the page access token
 * that reads their whole inbox. All of it sat in plaintext JSON, which means a
 * database backup — the artefact that gets copied to laptops, to staging, to a
 * support ticket — carried every channel credential on the platform in the
 * clear.
 *
 * ⚠️⚠️ THE WHOLE COLUMN CANNOT SIMPLY BE `encrypted:array`, and trying is the
 * obvious mistake. Twenty-two queries read *into* this JSON to route inbound
 * traffic — `credentials->page_id` resolves a Messenger webhook,
 * `credentials->business_account_id` a WhatsApp one, `credentials->app_id` the
 * chat widget. Encrypting the blob turns every one of those into a query that
 * matches nothing, and the symptom is not an error: it is messages silently
 * never arriving.
 *
 * So the split is per value, and it falls out of what the queries need:
 *
 *   identity keys  (page_id, app_id, business_account_id, user_id, …)
 *                  → stay plaintext, because the database has to match on them
 *   secret values  (access_token, token, refresh_token, webhook_secret, …)
 *                  → encrypted, because nothing ever looks them up
 *
 * ⚠️ Decryption is driven by a MARKER on the value, not by the key list.
 * That is what makes this deployable without a migration: a row written before
 * this existed is plaintext, carries no marker, and is handed back untouched.
 * Rows convert as they are re-saved, and `connections:encrypt-credentials`
 * finishes the rest. Reading and writing therefore disagree on purpose —
 * writing asks "is this key a secret?", reading asks "is this value already
 * encrypted?".
 *
 * ⚠️ Keys are matched EXACTLY, never by substring. `token_expires_at` and
 * `token_type` sit right next to `token` in the same payloads and are not
 * secrets; encrypting them would make an expiry comparison compare ciphertext.
 */
final class ConnectionCredentials
{
    /**
     * Values stored encrypted, wherever they appear in the payload.
     *
     * Shared with ConnectionResource, which uses the same list to decide what
     * never reaches the dashboard. One list, because "must not be readable in
     * the database" and "must not be readable in the browser" have never once
     * disagreed here, and two lists would drift on the first channel added.
     *
     * @var list<string>
     */
    public const SECRET_KEYS = [
        'access_token',
        'user_access_token',
        'refresh_token',
        'password',
        'app_secret',
        'client_secret',
        'secret',
        'api_key',
        'private_key',
        'token',
        ChatWebhookSecret::CREDENTIAL_KEY,
    ];

    /**
     * What an encrypted value looks like in the column.
     *
     * Deliberately readable in a database dump: somebody looking at a row needs
     * to be able to tell "this is protected" from "this is a token I am reading
     * right now", and an opaque blob answers neither question.
     */
    private const MARKER = 'pingly:enc:v1:';

    /** Encrypt the secret values in a decoded payload. */
    public static function protect(array $credentials): array
    {
        return self::walk($credentials, static function (string $key, mixed $value): mixed {
            if (! in_array($key, self::SECRET_KEYS, true)) {
                return $value;
            }

            // Only strings, and only ones not already protected: re-encrypting
            // on every save would still decrypt correctly but would rewrite the
            // whole column each time for nothing.
            if (! is_string($value) || $value === '' || self::isProtected($value)) {
                return $value;
            }

            return self::MARKER.Crypt::encryptString($value);
        });
    }

    /** Decrypt whatever in a decoded payload is marked as encrypted. */
    public static function reveal(array $credentials): array
    {
        return self::walk($credentials, static function (string $key, mixed $value): mixed {
            if (! is_string($value) || ! self::isProtected($value)) {
                return $value;
            }

            try {
                return Crypt::decryptString(substr($value, strlen(self::MARKER)));
            } catch (DecryptException $e) {
                // ⚠️ null, never the ciphertext. A caller that receives the
                // encrypted string would hand it to WhatsApp as a token and
                // read the rejection as "the customer's token expired". null
                // is the honest answer and fails where it can be diagnosed.
                //
                // In practice this means APP_KEY changed without the column
                // being re-encrypted, which is an incident rather than a bug:
                // every credential on the platform is unreadable until the old
                // key comes back.
                Log::error('A connection credential could not be decrypted', [
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        });
    }

    public static function isProtected(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, self::MARKER);
    }

    /** Whether any secret in this payload is still sitting in plaintext. */
    public static function needsProtecting(?array $credentials): bool
    {
        if (! $credentials) {
            return false;
        }

        return self::protect($credentials) !== $credentials;
    }

    /**
     * Apply $fn to every scalar leaf, carrying the key it sits under.
     *
     * ⚠️ Recursive because secrets are not always at the top level:
     * `pending_pages` is a list of pages each holding its own access token, and
     * `released_instance` keeps the token of the API Way instance it let go. A
     * one-level pass would leave both in the clear, which is the half-fix that
     * reads as a whole one.
     *
     * A list's numeric index is not a key anybody names, so the enclosing key
     * travels down into it — `pending_pages.0.access_token` is matched on
     * `access_token`, which is what the list actually contains.
     */
    private static function walk(array $items, callable $fn, string $parentKey = ''): array
    {
        foreach ($items as $key => $value) {
            $name = is_int($key) ? $parentKey : (string) $key;

            $items[$key] = is_array($value)
                ? self::walk($value, $fn, $name)
                : $fn($name, $value);
        }

        return $items;
    }
}
