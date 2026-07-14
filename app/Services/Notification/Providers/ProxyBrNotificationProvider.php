<?php

namespace App\Services\Notification\Providers;

use App\Services\Notification\Contracts\NotificationProvider;
use App\Services\Notification\NotificationConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * ProxyBR API (Directly) — a ProxyHub instance using CLIENT-LEVEL credentials
 * (a specific instance id + token), not the platform integrator token.
 *
 *   POST {base}/v1/message/send-text?instanceId={id}
 *   Header: Authorization: Bearer {token}
 *   Body:   { "phone": "<phone>", "message": "<text>" }
 *
 * Default base: https://whats-api.ipbr.pro. Fully self-contained.
 */
class ProxyBrNotificationProvider implements NotificationProvider
{
    public function key(): string
    {
        return 'proxybr';
    }

    public function isConfigured(): bool
    {
        return ! empty(NotificationConfig::proxybrToken())
            && ! empty(NotificationConfig::proxybrInstanceId());
    }

    public function send(string $to, string $message): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('ProxyBR notification provider is not configured.');
        }

        $endpoint = NotificationConfig::proxybrBaseUrl()
            . '/v1/message/send-text?instanceId=' . NotificationConfig::proxybrInstanceId();

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . NotificationConfig::proxybrToken(),
        ])->post($endpoint, [
            'phone' => $to,
            'message' => $message,
        ]);

        if ($response->failed()) {
            Log::error('ProxyBrNotificationProvider: send failed', [
                'to' => $to,
                'status' => $response->status(),
            ]);
            throw new RuntimeException('ProxyBR notification send failed with status ' . $response->status());
        }
    }
}
