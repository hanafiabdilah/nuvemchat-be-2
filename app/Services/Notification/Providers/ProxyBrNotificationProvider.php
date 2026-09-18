<?php

namespace App\Services\Notification\Providers;

use App\Services\Notification\Contracts\NotificationProvider;
use App\Services\Notification\NotificationConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * API Way (Directly) — an API Way instance using CLIENT-LEVEL credentials
 * (a specific instance id + token), not the platform integrator token.
 *
 *   POST {base}/v1/message/send-text?instanceId={id}
 *   Header: Authorization: Bearer {token}
 *   Body:   { "phone": "<phone>", "message": "<text>" }
 *
 * Default base: https://whats-api.ipbr.pro. Fully self-contained — it talks to
 * the instance's core directly, bypassing this platform's own API.
 *
 * ⚠️ The class, its key and its settings keep the pre-rebrand `proxybr` name:
 * they are stored values (settings rows, whatsapp_message_logs.provider), not
 * labels. Only the wording shown in the Back Office changed.
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
            throw new RuntimeException('API Way notification provider is not configured.');
        }

        $endpoint = NotificationConfig::proxybrBaseUrl()
            . '/v1/message/send-text?instanceId=' . NotificationConfig::proxybrInstanceId();

        $response = Http::asJson()
            ->acceptJson()
            ->connectTimeout(10)
            ->timeout(20)
            ->withHeaders([
                'Authorization' => 'Bearer ' . NotificationConfig::proxybrToken(),
            ])->post($endpoint, [
                'phone' => $to,
                'message' => $message,
            ]);

        if ($response->failed()) {
            Log::error('ProxyBrNotificationProvider: send failed', [
                'to' => $to,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('API Way notification send failed with status ' . $response->status());
        }
    }
}
