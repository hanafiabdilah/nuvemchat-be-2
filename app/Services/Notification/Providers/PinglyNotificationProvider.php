<?php

namespace App\Services\Notification\Providers;

use App\Services\Notification\Contracts\NotificationProvider;
use App\Services\Notification\NotificationConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Pingly — the platform's own public send-message API (the same one documented on
 * the tenant Developer page). Distinct from W-API/ProxyBR:
 *
 *   POST {base}/send-message
 *   Header: X-API-Key: <key>
 *   Body:   { "to": "<phone>", "message": "<text>" }
 *
 * Default base: https://chat.pingly.com.br/api/v1
 */
class PinglyNotificationProvider implements NotificationProvider
{
    public function key(): string
    {
        return 'pingly';
    }

    public function isConfigured(): bool
    {
        return ! empty(NotificationConfig::pinglyApiKey());
    }

    public function send(string $to, string $message): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Pingly notification provider is not configured.');
        }

        $response = Http::withHeaders([
            'X-API-Key' => NotificationConfig::pinglyApiKey(),
        ])->post(NotificationConfig::pinglyBaseUrl() . '/send-message', [
            'to' => $to,
            'message' => $message,
        ]);

        if ($response->failed()) {
            Log::error('PinglyNotificationProvider: send failed', [
                'to' => $to,
                'status' => $response->status(),
            ]);
            throw new RuntimeException('Pingly notification send failed with status ' . $response->status());
        }
    }
}
