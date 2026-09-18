<?php

namespace App\Services\Notification\Providers;

use App\Services\Notification\Contracts\NotificationProvider;
use App\Services\Notification\NotificationConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Pingly — the platform's own public send-message API (the same one documented
 * on the tenant Developer page):
 *
 *   POST {base}/send-message
 *   Header: X-Api-Key: <workspace API key>
 *   Body:   { "connection_id": "conn_…", "phone": "<phone>", "message": "<text>" }
 *
 * Default base: https://chat.pingly.com.br/api/v1
 *
 * ⚠️ The key is the WORKSPACE's (Developer › API keys), not a connection's. One
 * key now serves every number the workspace has, so the number that sends is
 * named per request as `connection_id` — the connection's public id, copied from
 * Connections › the connection's details. A key alone no longer says where a
 * message goes out from, which is why isConfigured() demands both.
 *
 * The phone is sent under both field names on purpose: the public API's handlers
 * spell the recipient differently per channel (`phone` on API Way, `to` on
 * WhatsApp Official) and each validates only its own, ignoring the other. One
 * body therefore works whichever connection the operator points this at, instead
 * of the notification pipeline having to know the channel behind an id.
 */
class PinglyNotificationProvider implements NotificationProvider
{
    public function key(): string
    {
        return 'pingly';
    }

    public function isConfigured(): bool
    {
        return ! empty(NotificationConfig::pinglyApiKey())
            && ! empty(NotificationConfig::pinglyConnectionId());
    }

    public function send(string $to, string $message): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Pingly notification provider is not configured.');
        }

        $response = Http::asJson()
            ->acceptJson()
            ->connectTimeout(10)
            ->timeout(20)
            ->withHeaders([
                'X-Api-Key' => NotificationConfig::pinglyApiKey(),
            ])->post(NotificationConfig::pinglyBaseUrl() . '/send-message', [
                'connection_id' => NotificationConfig::pinglyConnectionId(),
                'phone' => $to,
                'to' => $to,
                'message' => $message,
            ]);

        if ($response->failed()) {
            Log::error('PinglyNotificationProvider: send failed', [
                'to' => $to,
                'status' => $response->status(),
                // Verbatim: this surface is read by whoever fixes the
                // integration, and the API answers refusals with a `code`
                // (connection_inactive, api_key_invalid, …) that names the fix.
                'body' => $response->body(),
            ]);
            throw new RuntimeException('Pingly notification send failed with status ' . $response->status());
        }
    }
}
