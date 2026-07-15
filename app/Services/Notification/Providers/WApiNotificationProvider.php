<?php

namespace App\Services\Notification\Providers;

use App\Services\Notification\Contracts\NotificationProvider;
use App\Services\Notification\NotificationConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * W-API (Directly) — a W-API instance configured directly.
 *
 *   POST {base}/message/send-text?instanceId={id}
 *   Header: Authorization: Bearer {token}
 *   Body:   { "phone": "<phone>", "message": "<text>" }
 *
 * Default base: https://api.w-api.app/v1. Fully self-contained.
 */
class WApiNotificationProvider implements NotificationProvider
{
    public function key(): string
    {
        return 'wapi';
    }

    public function isConfigured(): bool
    {
        return ! empty(NotificationConfig::wapiToken())
            && ! empty(NotificationConfig::wapiInstanceId());
    }

    public function send(string $to, string $message): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('W-API notification provider is not configured.');
        }

        $endpoint = NotificationConfig::wapiBaseUrl()
            . '/message/send-text?instanceId=' . NotificationConfig::wapiInstanceId();

        $response = Http::asJson()
            ->acceptJson()
            ->connectTimeout(10)
            ->timeout(20)
            ->withHeaders([
                'Authorization' => 'Bearer ' . NotificationConfig::wapiToken(),
            ])->post($endpoint, [
                'phone' => $to,
                'message' => $message,
            ]);

        if ($response->failed()) {
            Log::error('WApiNotificationProvider: send failed', [
                'to' => $to,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('W-API notification send failed with status ' . $response->status());
        }
    }
}
