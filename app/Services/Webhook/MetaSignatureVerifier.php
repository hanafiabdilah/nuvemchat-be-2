<?php

namespace App\Services\Webhook;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Verifies Meta's `X-Hub-Signature-256` header for incoming webhooks
 * (WhatsApp Cloud API, Instagram, Messenger).
 *
 * Meta signs the EXACT raw request body with HMAC-SHA256 keyed by the app
 * secret and sends it as:
 *
 *     X-Hub-Signature-256: sha256=<hex-encoded hmac>
 *
 * The signature must be computed over the raw payload (`$request->getContent()`),
 * never the re-encoded/parsed array, or it will never match.
 */
class MetaSignatureVerifier
{
    /**
     * @param  string|null  $appSecret     The Meta app secret used to sign the payload.
     * @param  string       $channelLabel  Label for logging (e.g. "whatsapp", "instagram").
     * @return bool  True when the request is authentic (or the check is skipped).
     */
    public static function verify(Request $request, ?string $appSecret, string $channelLabel): bool
    {
        // No secret configured → degrade to the previous (unverified) behavior
        // instead of dropping every webhook, but make the gap loud in the logs.
        if (empty($appSecret)) {
            Log::warning('Meta webhook signature check skipped: no app secret configured', [
                'channel' => $channelLabel,
            ]);
            return true;
        }

        $header = $request->header('X-Hub-Signature-256');

        if (!$header || !str_starts_with($header, 'sha256=')) {
            Log::warning('Meta webhook rejected: missing or malformed X-Hub-Signature-256 header', [
                'channel' => $channelLabel,
            ]);
            return false;
        }

        $provided = substr($header, strlen('sha256='));
        $expected = hash_hmac('sha256', $request->getContent(), $appSecret);

        if (!hash_equals($expected, $provided)) {
            Log::warning('Meta webhook rejected: signature mismatch', [
                'channel' => $channelLabel,
            ]);
            return false;
        }

        return true;
    }
}
