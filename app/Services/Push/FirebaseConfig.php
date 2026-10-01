<?php

namespace App\Services\Push;

use App\Models\Setting;
use Illuminate\Validation\ValidationException;

/**
 * The Firebase service account that sends push notifications to the mobile app.
 *
 * Stored whole (the JSON Google hands out) in the `settings` table, encrypted,
 * uploaded from Back Office → Integrations → Firebase. Read only here: nothing
 * else in the platform should know which field of that file holds the key.
 *
 * ⚠️ It must come from the same Firebase project as the app build. A token
 * minted by another project is refused with SENDER_ID_MISMATCH, and no setting
 * on this side can fix that.
 */
class FirebaseConfig
{
    public const KEY_SERVICE_ACCOUNT = 'firebase.service_account';

    /** @return array<string, mixed>|null */
    public static function serviceAccount(): ?array
    {
        $raw = Setting::get(self::KEY_SERVICE_ACCOUNT);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    public static function isConfigured(): bool
    {
        return self::serviceAccount() !== null;
    }

    public static function projectId(): ?string
    {
        return self::serviceAccount()['project_id'] ?? null;
    }

    public static function clientEmail(): ?string
    {
        return self::serviceAccount()['client_email'] ?? null;
    }

    /**
     * Validate and keep an uploaded service account file.
     *
     * Checked before it is stored, not on first send: a wrong file that saves
     * cleanly is found by the first agent who misses a customer.
     *
     * @return array<string, mixed>
     */
    public static function store(string $json): array
    {
        $account = json_decode($json, true);

        $problem = match (true) {
            ! is_array($account) => 'The file is not valid JSON.',
            ($account['type'] ?? null) !== 'service_account' => 'This JSON is not a service account ("type" must be "service_account"). Download it from Firebase → Project settings → Service accounts → Generate new private key.',
            empty($account['project_id']) => 'The JSON has no "project_id".',
            empty($account['client_email']) => 'The JSON has no "client_email".',
            empty($account['private_key']) || openssl_pkey_get_private((string) $account['private_key']) === false => 'The "private_key" in the JSON could not be read.',
            default => null,
        };

        if ($problem !== null) {
            throw ValidationException::withMessages(['service_account' => $problem]);
        }

        // Only what signing needs, re-encoded: nothing else in that file is
        // ours to keep.
        $kept = array_intersect_key($account, array_flip([
            'type', 'project_id', 'private_key_id', 'private_key', 'client_email', 'client_id', 'token_uri',
        ]));

        Setting::set(self::KEY_SERVICE_ACCOUNT, json_encode($kept));
        FcmClient::forgetAccessToken();

        return $kept;
    }

    public static function clear(): void
    {
        Setting::set(self::KEY_SERVICE_ACCOUNT, null);
        FcmClient::forgetAccessToken();
    }
}
