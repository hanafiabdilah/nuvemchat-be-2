<?php

namespace App\Services\Push;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Firebase Cloud Messaging, HTTP v1.
 *
 * Written against the REST API directly rather than a Firebase SDK: one OAuth
 * token exchange (a JWT signed with the service account key) and one POST per
 * message is the whole surface, and an SDK would add a dependency tree to the
 * image for it.
 */
class FcmClient
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';

    private const TOKEN_CACHE_KEY = 'push:fcm:access-token';

    /**
     * Send one message to one token.
     *
     * @param  array<string, mixed>  $message  FCM `message` without `token`
     */
    public function send(string $token, array $message, bool $validateOnly = false): PushResult
    {
        $projectId = FirebaseConfig::projectId();
        if (! $projectId) {
            return new PushResult(PushResult::FAILED, 'firebase_not_configured');
        }

        $body = ['message' => ['token' => $token] + $message];
        if ($validateOnly) {
            $body['validate_only'] = true;
        }

        $response = $this->post($projectId, $body);

        // An access token revoked before its hour was up: mint a new one once.
        if ($response->status() === 401) {
            self::forgetAccessToken();
            $response = $this->post($projectId, $body);
        }

        return $this->interpret($response);
    }

    /**
     * Proof that the stored service account can actually send: an OAuth token,
     * then a validate-only send to a token that cannot exist. FCM answering
     * "invalid token" is the success case — it means the project exists, the
     * FCM API is enabled and this account may use it.
     *
     * @return array{ok: bool, message: string}
     */
    public function test(): array
    {
        if (! FirebaseConfig::isConfigured()) {
            return ['ok' => false, 'message' => 'No service account uploaded.'];
        }

        try {
            self::forgetAccessToken();
            $this->accessToken();
        } catch (RuntimeException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $result = $this->send('pingly-connection-test', ['data' => ['type' => 'test']], validateOnly: true);

        if ($result->outcome === PushResult::INVALID_TOKEN || $result->sent()) {
            return ['ok' => true, 'message' => 'Credentials work: project '.FirebaseConfig::projectId().' accepts sends.'];
        }

        return ['ok' => false, 'message' => $result->error ?? 'Unknown failure.'];
    }

    public static function forgetAccessToken(): void
    {
        Cache::forget(self::TOKEN_CACHE_KEY);
    }

    private function post(string $projectId, array $body): Response
    {
        return Http::timeout(10)
            ->withToken($this->accessToken())
            ->acceptJson()
            ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", $body);
    }

    private function interpret(Response $response): PushResult
    {
        if ($response->successful()) {
            return new PushResult(PushResult::SENT, messageId: $response->json('name'));
        }

        $status = $response->status();
        $errorCode = collect($response->json('error.details', []))
            ->pluck('errorCode')->filter()->first();
        $text = trim(($errorCode ? "{$errorCode}: " : '').(string) $response->json('error.message', $response->body()));
        $text = mb_substr($text, 0, 180);

        // The token no longer reaches an installed app (uninstalled, data
        // cleared, or a string that was never a token).
        if ($errorCode === 'UNREGISTERED' || $status === 404
            || ($status === 400 && in_array($errorCode, [null, 'INVALID_ARGUMENT'], true)
                && str_contains(strtolower($text), 'registration token'))) {
            return new PushResult(PushResult::INVALID_TOKEN, $text);
        }

        if ($status === 429 || $status >= 500 || in_array($errorCode, ['QUOTA_EXCEEDED', 'UNAVAILABLE', 'INTERNAL'], true)) {
            return new PushResult(PushResult::RETRYABLE, $text);
        }

        // SENDER_ID_MISMATCH, THIRD_PARTY_AUTH_ERROR, permission denied: the
        // configuration is wrong, not the phone. The token stays.
        return new PushResult(PushResult::FAILED, $text);
    }

    private function accessToken(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $account = FirebaseConfig::serviceAccount();
        if ($account === null) {
            throw new RuntimeException('No service account uploaded.');
        }

        $tokenUri = $account['token_uri'] ?? self::DEFAULT_TOKEN_URI;
        $now = time();

        $response = Http::asForm()->timeout(10)->post($tokenUri, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $this->jwt($account, $tokenUri, $now),
        ]);

        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token)) {
            // Google's own words: "invalid_grant: Invalid JWT Signature" is
            // exactly what the operator needs to see (a revoked key).
            $reason = trim($response->json('error', '').': '.$response->json('error_description', ''), ': ');
            throw new RuntimeException('Google refused the service account'.($reason ? " ({$reason})" : '').'.');
        }

        $ttl = max(60, (int) $response->json('expires_in', 3600) - 300);
        Cache::put(self::TOKEN_CACHE_KEY, $token, $ttl);

        return $token;
    }

    /** @param  array<string, mixed>  $account */
    private function jwt(array $account, string $audience, int $now): string
    {
        $encode = fn (array $part) => rtrim(strtr(base64_encode(json_encode($part)), '+/', '-_'), '=');

        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        if (! empty($account['private_key_id'])) {
            $header['kid'] = $account['private_key_id'];
        }

        $unsigned = $encode($header).'.'.$encode([
            'iss' => $account['client_email'],
            'scope' => self::SCOPE,
            'aud' => $audience,
            'iat' => $now,
            'exp' => $now + 3600,
        ]);

        $key = openssl_pkey_get_private((string) $account['private_key']);
        if ($key === false || ! openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('The service account private_key could not sign.');
        }

        return $unsigned.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }
}
