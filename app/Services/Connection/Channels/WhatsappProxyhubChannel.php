<?php

namespace App\Services\Connection\Channels;

use App\Enums\Connection\Status;
use App\Exceptions\ConnectionException;
use App\Models\Connection;
use App\Services\Connection\ChannelInterface;
use App\Services\Connection\Proxy\ProxyValidator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp ProxyHub channel. Mirrors W-API but is managed-only (the system always
 * creates the instance via the integrator) and sets a proxy on the instance at
 * creation time. proxyMode: shared (server IP) | dedicated (ProxyBR IPv4) |
 * custom (the user supplies ip:port:username:password, validated & detected).
 */
class WhatsappProxyhubChannel implements ChannelInterface
{
    private const PROXY_MODES = ['shared', 'dedicated', 'custom'];

    private const WEBHOOK_EVENTS = ['connected', 'disconnected', 'delivery', 'status', 'presence'];

    public function __construct(
        private readonly ProxyValidator $proxyValidator = new ProxyValidator(),
    ) {}

    private function base(): string
    {
        return rtrim(config('services.proxyhub.base_url'), '/');
    }

    private function integratorToken(): string
    {
        return (string) config('services.proxyhub.integrator_token');
    }

    public function connect(Connection $connection, array $data)
    {
        $data['proxy_mode'] = $data['proxy_mode'] ?? $connection->credentials['proxy_mode'] ?? 'shared';

        validator($data, [
            'proxy_mode' => ['required', 'in:' . implode(',', self::PROXY_MODES)],
            'proxy' => ['required_if:proxy_mode,custom', 'string'],
        ])->validate();

        // Resolve proxy URL. Only custom needs detection/validation.
        $proxyUrl = '';
        $proxyMeta = [];
        if ($data['proxy_mode'] === 'custom') {
            $detected = $this->proxyValidator->validate($data['proxy']);
            $proxyUrl = $detected['url'];
            $proxyMeta = [
                'proxy_scheme' => $detected['scheme'],
                'proxy_host' => $detected['host'] . ':' . $detected['port'],
            ];
        }

        $connection = $this->handleManagedInstance($connection, $data['proxy_mode'], $proxyUrl, $proxyMeta);

        if ($connection->status === Status::Active) {
            return;
        }

        // Newly created instance needs a moment before the QR is available.
        if ($connection->credentials['newly_created'] ?? false) {
            sleep(3);
            $connection->update([
                'credentials' => array_merge($connection->credentials, ['newly_created' => null]),
            ]);
            $connection->refresh();
        }

        $this->retrieveQrCode($connection);
    }

    private function handleManagedInstance(Connection $connection, string $proxyMode, string $proxyUrl, array $proxyMeta): Connection
    {
        // Instance already created — just refresh status.
        if (isset($connection->credentials['instance_id'], $connection->credentials['token'])) {
            return $this->checkInstanceStatus($connection);
        }

        $webhookUrl = route('webhook.chat', ['id' => $connection->id]);

        $payload = [
            'instanceName' => config('app.name') . ' - #' . $connection->id,
            'proxyMode' => $proxyMode,
            'proxyUrl' => $proxyUrl,
            'rejectCalls' => true,
            'callMessage' => 'This number does not accept calls.',
            'webhookReceivedUrl' => $webhookUrl,
        ];

        Log::info('Creating WhatsApp ProxyHub managed instance', ['connection' => $connection->id, 'proxy_mode' => $proxyMode]);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->integratorToken(),
        ])->post($this->base() . '/v1/integrator/create-instance', $payload);

        $responseJson = $response->json();

        if ($response->failed()) {
            Log::error('WhatsApp ProxyHub managed instance creation failed', ['connection' => $connection->id, 'response' => $responseJson, 'status' => $response->status()]);
            throw new ConnectionException($responseJson['message'] ?? 'Failed to create managed instance on ProxyHub', $response->status() ?: 500);
        }

        $connection->update([
            'status' => Status::Pending,
            'credentials' => array_merge([
                'instance_id' => $responseJson['instanceId'],
                'token' => $responseJson['token'],
                'is_managed' => true,
                'newly_created' => true,
                'proxy_mode' => $proxyMode,
            ], $proxyMeta),
        ]);

        // create-instance only registers the received webhook; register the rest.
        $this->setupWebhooks($connection, $webhookUrl);

        return $connection;
    }

    private function setupWebhooks(Connection $connection, string $webhookUrl): void
    {
        foreach (self::WEBHOOK_EVENTS as $event) {
            $endpoint = $this->base() . '/v1/instance/update-webhook-' . $event . '?instanceId=' . $connection->credentials['instance_id'];

            try {
                Http::withHeaders([
                    'Authorization' => 'Bearer ' . $connection->credentials['token'],
                ])->put($endpoint, ['value' => $webhookUrl]);
            } catch (\Throwable $th) {
                Log::warning('WhatsApp ProxyHub webhook setup failed', ['event' => $event, 'connection' => $connection->id, 'error' => $th->getMessage()]);
            }
        }
    }

    private function retrieveQrCode(Connection $connection): void
    {
        $qr = Http::withHeaders([
            'Authorization' => 'Bearer ' . $connection->credentials['token'],
        ])->get($this->base() . '/v1/instance/qr-code?instanceId=' . $connection->credentials['instance_id']);

        $qrJson = $qr->json();

        if ($qr->failed()) {
            Log::error('WhatsApp ProxyHub QR request failed', ['connection' => $connection->id, 'response' => $qrJson, 'status' => $qr->status()]);
            throw new ConnectionException($qrJson['message'] ?? 'Failed to retrieve QR code from ProxyHub', $qr->status() ?: 500);
        }

        // ProxyHub wraps instance responses as { success, data: { qrcode: <data URI> } }.
        $qrCode = $qrJson['data']['qrcode']
            ?? $qrJson['qrcode'] ?? $qrJson['qrCode'] ?? $qrJson['value'] ?? null;

        $connection->update([
            'credentials' => array_merge($connection->credentials, ['qr_code' => $qrCode]),
        ]);
    }

    private function checkInstanceStatus(Connection $connection): Connection
    {
        $status = Http::withHeaders([
            'Authorization' => 'Bearer ' . $connection->credentials['token'],
        ])->get($this->base() . '/v1/instance/status-instance?instanceId=' . $connection->credentials['instance_id']);

        $statusJson = $status->json();

        if ($status->failed()) {
            Log::error('WhatsApp ProxyHub status request failed', ['connection' => $connection->id, 'response' => $statusJson, 'status' => $status->status()]);
            throw new ConnectionException($statusJson['message'] ?? 'Failed to connect to ProxyHub', $status->status() ?: 500);
        }

        // ProxyHub wraps instance responses as { success, data: { connected: bool } }.
        $connected = $statusJson['data']['connected'] ?? $statusJson['connected'] ?? false;

        $connection->update([
            'status' => $connected === true ? Status::Active : Status::Inactive,
        ]);

        return $connection;
    }

    public function checkStatus(Connection $connection): void
    {
        try {
            $this->checkInstanceStatus($connection);
        } catch (\Throwable $th) {
            $connection->update(['status' => Status::Inactive]);
            throw $th instanceof ConnectionException
                ? $th
                : new ConnectionException('An error occurred while checking ProxyHub connection status', 500);
        }
    }

    public function disconnect(Connection $connection): void
    {
        try {
            Http::withHeaders([
                'Authorization' => 'Bearer ' . $connection->credentials['token'],
            ])->get($this->base() . '/v1/instance/disconnect?instanceId=' . $connection->credentials['instance_id']);
        } catch (\Throwable $th) {
            Log::warning('Error disconnecting ProxyHub, marking inactive anyway', ['connection' => $connection->id, 'error' => $th->getMessage()]);
        }

        $connection->update([
            'status' => Status::Inactive,
            'credentials' => array_merge($connection->credentials, ['qr_code' => null]),
        ]);
    }

    public function deleteManagedInstance(Connection $connection): void
    {
        if (empty($connection->credentials['instance_id'])) {
            return;
        }

        try {
            Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->integratorToken(),
            ])->delete($this->base() . '/v1/delete-instance?instanceId=' . $connection->credentials['instance_id']);
        } catch (\Throwable $th) {
            Log::warning('Error deleting ProxyHub managed instance, continuing', ['connection' => $connection->id, 'error' => $th->getMessage()]);
        }
    }
}
