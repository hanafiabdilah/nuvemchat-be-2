<?php

namespace App\Services\Webhooks;

use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sends signed events to a workspace's endpoints.
 *
 * Signature: `X-Pingly-Signature: t=<unix>,v1=<hex>`, where v1 is
 * HMAC-SHA256 of "{t}.{raw body}" with the endpoint's secret — the same shape
 * the payment service signs our own webhooks with. The receiver checks it
 * against the raw body (a re-encoded JSON has different bytes) and rejects
 * old timestamps.
 *
 * Delivery is at-least-once: the event id (`X-Pingly-Delivery` and the body's
 * `id`) is what the receiver deduplicates on.
 */
final class WebhookDispatcher
{
    public const DELIVERED = 'delivered';

    public const RETRY = 'retry';

    public const GIVE_UP = 'give_up';

    private const TIMEOUT_SECONDS = 10;

    /**
     * Queue one delivery per active endpoint that subscribes to the event.
     *
     * @param  array<string, mixed>  $data
     * @return int how many deliveries were queued
     */
    public function emit(int $tenantId, string $event, array $data): int
    {
        $endpoints = WebhookEndpoint::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->get()
            ->filter(fn (WebhookEndpoint $endpoint) => $endpoint->subscribes($event));

        if ($endpoints->isEmpty()) {
            return 0;
        }

        $eventId = 'evt_'.Str::lower((string) Str::ulid());
        $body = $this->encode($eventId, $event, $data);

        foreach ($endpoints as $endpoint) {
            $delivery = WebhookDelivery::create([
                'webhook_endpoint_id' => $endpoint->id,
                'event_id' => $eventId,
                'event' => $event,
                'payload' => $body,
                'status' => WebhookDelivery::PENDING,
            ]);

            // A failing endpoint must never fail whatever moved the lead. On a
            // real queue this only enqueues; on the sync driver it also runs,
            // and a refused delivery throws here.
            try {
                DeliverWebhook::dispatch($delivery->id);
            } catch (\Throwable $e) {
                Log::warning('Webhook delivery failed on first attempt', [
                    'delivery_id' => $delivery->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $endpoints->count();
    }

    /** A delivery row for the "send test" button — sent inline, not queued. */
    public function ping(WebhookEndpoint $endpoint): WebhookDelivery
    {
        $eventId = 'evt_'.Str::lower((string) Str::ulid());

        $delivery = WebhookDelivery::create([
            'webhook_endpoint_id' => $endpoint->id,
            'event_id' => $eventId,
            'event' => WebhookEvents::PING,
            'payload' => $this->encode($eventId, WebhookEvents::PING, [
                'message' => 'Teste de webhook do Pingly. Se esta requisição chegou, a URL e a assinatura estão funcionando.',
            ]),
            'status' => WebhookDelivery::PENDING,
        ]);

        if ($this->deliver($delivery) !== self::DELIVERED) {
            $delivery->forceFill(['status' => WebhookDelivery::FAILED])->save();
        }

        return $delivery->fresh();
    }

    /**
     * One attempt. The row records the outcome; the return value says whether
     * another attempt makes sense.
     */
    public function deliver(WebhookDelivery $delivery): string
    {
        $endpoint = $delivery->endpoint;

        $delivery->forceFill(['attempts' => $delivery->attempts + 1])->save();

        if (! $endpoint || ! $endpoint->is_active) {
            return $this->finish($delivery, WebhookDelivery::FAILED, error: 'O webhook foi desativado ou removido.');
        }

        if (WebhookUrl::resolvesToPrivate($endpoint->url)) {
            return $this->finish($delivery, WebhookDelivery::FAILED, error: 'A URL aponta para um endereço que não é público.');
        }

        $timestamp = now()->getTimestamp();

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(5)
                ->withOptions(['allow_redirects' => false])
                ->withHeaders([
                    'X-Pingly-Signature' => self::signature($delivery->payload, (string) $endpoint->secret, $timestamp),
                    'X-Pingly-Event' => $delivery->event,
                    'X-Pingly-Delivery' => $delivery->event_id,
                    'User-Agent' => 'Pingly-Webhooks/1.0',
                ])
                ->withBody($delivery->payload, 'application/json')
                ->post($endpoint->url);
        } catch (ConnectionException $e) {
            $this->finish($delivery, WebhookDelivery::PENDING, error: 'Sem resposta da URL (tempo esgotado ou conexão recusada).');

            return self::RETRY;
        }

        $endpoint->forceFill([
            'last_delivery_at' => now(),
            'last_response_status' => $response->status(),
        ])->save();

        if ($response->successful()) {
            $this->finish($delivery, WebhookDelivery::DELIVERED, $response->status(), $response->body());

            return self::DELIVERED;
        }

        $this->finish($delivery, WebhookDelivery::PENDING, $response->status(), $response->body(), 'A URL respondeu com HTTP '.$response->status().'.');

        return self::RETRY;
    }

    public static function signature(string $body, string $secret, int $timestamp): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /** @param  array<string, mixed>  $data */
    private function encode(string $eventId, string $event, array $data): string
    {
        return json_encode([
            'id' => $eventId,
            'event' => $event,
            'created_at' => now()->utc()->toIso8601ZuluString(),
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function finish(WebhookDelivery $delivery, string $status, ?int $httpStatus = null, ?string $body = null, ?string $error = null): string
    {
        $delivery->forceFill([
            'status' => $status,
            'response_status' => $httpStatus,
            'response_body' => $body !== null ? mb_substr($body, 0, 1000) : null,
            'error' => $error,
            'delivered_at' => $status === WebhookDelivery::DELIVERED ? now() : null,
        ])->save();

        return $status === WebhookDelivery::FAILED ? self::GIVE_UP : $status;
    }
}
