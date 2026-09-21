<?php

namespace Tests\Support;

use App\Models\Setting;
use App\Services\Connection\Meta\FacebookConfig;
use Illuminate\Testing\TestResponse;

/**
 * Posting to a Meta webhook the way Meta does.
 *
 * Signature verification used to wave a delivery through when no app secret
 * was configured, so tests could post an unsigned body and be served. It
 * refuses now — a missing secret is an outage, not a shortcut — which means a
 * test that wants to reach the handler has to sign like the real sender.
 *
 * Signing here rather than disabling the check keeps these tests covering the
 * path that actually runs in production.
 */
class MetaWebhook
{
    public const SECRET = 'test-app-secret';

    /** Configure the secret the verifier will check against. */
    public static function configure(): void
    {
        Setting::set(FacebookConfig::KEY_APP_SECRET, self::SECRET);
    }

    /**
     * POST a signed payload.
     *
     * ⚠️ The signature covers the exact bytes sent, so the body is encoded once
     * and that same string is both hashed and posted. Re-encoding the array for
     * the request would reorder keys and produce a body the hash does not match
     * — the same trap MetaSignatureVerifier's docblock warns about.
     */
    public static function post(object $test, array $payload, string $path = '/webhook/whatsapp'): TestResponse
    {
        self::configure();

        $body = json_encode($payload);

        return $test->call(
            'POST',
            $path,
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::SECRET),
            ],
            $body,
        );
    }
}
