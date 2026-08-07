<?php

namespace App\Services\Connection\Channels;

use App\Enums\Connection\Status;
use App\Exceptions\ApiwayPartnerException;
use App\Exceptions\ConnectionException as AppConnectionException;
use App\Models\ApiwayInstance;
use App\Models\Connection;
use App\Services\Connection\Apiway\ApiwayService;
use App\Services\Connection\ChannelInterface;
use App\Services\Connection\Proxy\ApiwayConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp API Way channel. Instances are PURCHASED assets provisioned by
 * ProxyBR (see ApiwayService); connecting links one owned, unused instance to
 * this Connection. Deleting/disconnecting never destroys the instance — it
 * returns to the tenant's pool. Everything here talks straight to the core
 * with the per-instance token; the partner API only hands that token over
 * (once — it's stored on the ApiwayInstance row afterwards).
 */
class WhatsappApiwayChannel implements ChannelInterface
{
    /** Core webhook slots, all pointed at the same chat endpoint ('received' is the inbound-message one). */
    private const WEBHOOK_EVENTS = ['received', 'connected', 'disconnected', 'delivery', 'status', 'presence'];

    private function base(): string
    {
        return ApiwayConfig::baseUrl();
    }

    public function connect(Connection $connection, array $data)
    {
        if (! isset($connection->credentials['instance_id'], $connection->credentials['token'])) {
            $this->linkInstance($connection, $data);
        } else {
            $this->checkInstanceStatus($connection);
        }

        // Re-asserted on every connect (link or QR/status refresh) so a failed
        // or stale registration heals itself the next time the wizard opens.
        $this->registerWebhook($connection);

        // Opt-in to importing the chat list once the instance pairs.
        if (array_key_exists('import_history', $data)) {
            $connection->update([
                'credentials' => array_merge($connection->credentials ?? [], [
                    'import_history' => filter_var($data['import_history'], FILTER_VALIDATE_BOOLEAN),
                ]),
            ]);
            $connection->refresh();
        }

        if ($connection->status === Status::Active) {
            \App\Jobs\ImportWhatsappChatHistory::dispatchIfPending($connection);

            return;
        }

        $this->retrieveQrCode($connection);
    }

    /**
     * Link an owned, available instance to this connection: fetch its API
     * token via the partner console and persist. Webhook registration happens
     * in connect(), straight on the core, once credentials are stored.
     */
    private function linkInstance(Connection $connection, array $data): void
    {
        validator($data, [
            'apiway_instance_id' => ['required', 'integer'],
        ])->validate();

        /** @var ApiwayInstance|null $instance */
        $instance = ApiwayInstance::query()
            ->whereKey($data['apiway_instance_id'])
            ->where('tenant_id', $connection->tenant_id)
            ->with('subscription')
            ->first();

        if (! $instance) {
            throw new AppConnectionException('Instância API Way não encontrada.', 404);
        }

        if ($instance->connection_id !== null && $instance->connection_id !== $connection->id) {
            throw new AppConnectionException('Esta instância já está em uso por outra conexão.', 422);
        }

        if (! $instance->isUsable()) {
            throw new AppConnectionException('Esta instância não está mais ativa. Renove ou contrate uma nova.', 422);
        }

        try {
            $token = app(ApiwayService::class)->instanceCoreToken($instance);
        } catch (ApiwayPartnerException $e) {
            Log::error('API Way instance token fetch failed', ['instance' => $instance->id, 'error' => $e->getMessage()]);
            throw new AppConnectionException(
                $e->getErrorCode() === 'token_missing'
                    ? $e->getMessage()
                    : 'Não foi possível obter as credenciais da instância. Tente novamente.',
                502,
            );
        }

        DB::transaction(function () use ($connection, $instance, $token) {
            $instance->update(['connection_id' => $connection->id]);

            $connection->update([
                'status' => Status::Pending,
                'credentials' => [
                    'instance_id' => $instance->provider_instance_id,
                    'token' => $token,
                    'is_managed' => true,
                    'apiway_instance_id' => $instance->id,
                ],
            ]);
        });

        $connection->refresh();
    }

    /**
     * Point the instance's webhooks at our chat endpoint, straight on the core
     * with the instance token — the partner route proved unreliable (accepted
     * the PUT but the webhook never took effect). Same per-event endpoints the
     * pre-partner channel used. Failures are non-fatal (registration re-runs on
     * every connect), but without `received` inbound messages never arrive —
     * hence the loud log.
     */
    private function registerWebhook(Connection $connection): void
    {
        $webhookUrl = route('webhook.chat', ['id' => $connection->id]);
        $instanceId = $connection->credentials['instance_id'];
        $token = $connection->credentials['token'];

        foreach (self::WEBHOOK_EVENTS as $event) {
            // Legacy core builds read {value}; newer ones read {url, events}.
            // Send both shapes in one body — each parser picks its own field.
            $body = ['value' => $webhookUrl, 'url' => $webhookUrl];

            if ($event === 'received') {
                $body['events'] = ['Message', 'Receipt', 'ReadReceipt', 'Connected', 'Disconnected', 'LoggedOut'];
            }

            try {
                Http::withToken($token)
                    ->connectTimeout(15)
                    ->timeout(30)
                    ->retry(2, 500)
                    ->put($this->base() . '/v1/instance/update-webhook-' . $event . '?instanceId=' . $instanceId, $body);
            } catch (\Throwable $th) {
                Log::log($event === 'received' ? 'error' : 'warning', 'API Way webhook registration failed', [
                    'event' => $event,
                    'connection' => $connection->id,
                    'error' => $th->getMessage(),
                ]);
            }
        }
    }

    private function retrieveQrCode(Connection $connection): void
    {
        $qr = Http::withHeaders([
            'Authorization' => 'Bearer ' . $connection->credentials['token'],
        ])->connectTimeout(15)
            ->timeout(30)
            ->retry(3, 800)
            ->get($this->base() . '/v1/instance/qr-code?instanceId=' . $connection->credentials['instance_id']);

        $qrJson = $qr->json();

        if ($qr->failed()) {
            Log::error('WhatsApp API Way QR request failed', ['connection' => $connection->id, 'response' => $qrJson, 'status' => $qr->status()]);
            throw new AppConnectionException($qrJson['message'] ?? 'Failed to retrieve QR code from API Way', $qr->status() ?: 500);
        }

        // API Way wraps instance responses as { success, data: { qrcode: <data URI> } }.
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
        ])->connectTimeout(15)
            ->timeout(30)
            ->retry(3, 800)
            ->get($this->base() . '/v1/instance/status-instance?instanceId=' . $connection->credentials['instance_id']);

        $statusJson = $status->json();

        if ($status->failed()) {
            Log::error('WhatsApp API Way status request failed', ['connection' => $connection->id, 'response' => $statusJson, 'status' => $status->status()]);
            throw new AppConnectionException($statusJson['message'] ?? 'Failed to connect to API Way', $status->status() ?: 500);
        }

        // API Way wraps instance responses as
        // { success, data: { connected: bool, loggedIn: bool, jid } }.
        // The instance is only usable when it's BOTH connected to WhatsApp AND
        // logged in (paired) — connected alone can mean "waiting for QR scan".
        $data = $statusJson['data'] ?? $statusJson;
        $isActive = ($data['connected'] ?? false) === true && ($data['loggedIn'] ?? false) === true;

        $updates = ['status' => $isActive ? Status::Active : Status::Inactive];

        // The paired number comes from the session JID; keep it on the
        // credentials so the SPA can show which WhatsApp number this is.
        if ($isActive && ($phone = $this->phoneFromJid($data['jid'] ?? null))) {
            $updates['credentials'] = array_merge($connection->credentials, ['phone_number' => $phone]);
        }

        $connection->update($updates);

        \App\Jobs\ImportWhatsappChatHistory::dispatchIfPending($connection);

        return $connection;
    }

    /**
     * Bare phone from a whatsmeow session JID:
     * "5511999999999:73@s.whatsapp.net" → "5511999999999".
     */
    private function phoneFromJid(?string $jid): ?string
    {
        if (! $jid) {
            return null;
        }

        $user = explode('@', $jid)[0];   // strip server
        $user = explode(':', $user)[0];  // strip device id
        $user = explode('.', $user)[0];  // strip any agent suffix

        return $user !== '' ? $user : null;
    }

    public function checkStatus(Connection $connection): void
    {
        try {
            $this->checkInstanceStatus($connection);
        } catch (\Throwable $th) {
            $connection->update(['status' => Status::Inactive]);
            throw $th instanceof AppConnectionException
                ? $th
                : new AppConnectionException('An error occurred while checking API Way connection status', 500);
        }
    }

    public function disconnect(Connection $connection): void
    {
        try {
            Http::withHeaders([
                'Authorization' => 'Bearer ' . $connection->credentials['token'],
            ])->connectTimeout(15)
                ->timeout(20)
                ->get($this->base() . '/v1/instance/disconnect?instanceId=' . $connection->credentials['instance_id']);
        } catch (\Throwable $th) {
            Log::warning('Error disconnecting API Way, marking inactive anyway', ['connection' => $connection->id, 'error' => $th->getMessage()]);
        }

        $connection->update([
            'status' => Status::Inactive,
            // A fresh pairing may be a different number — drop the stale one.
            'credentials' => array_merge($connection->credentials, ['qr_code' => null, 'phone_number' => null]),
        ]);
    }

    /**
     * Return the linked instance to the tenant's pool. The purchased asset
     * (and its ProxyBR subscription) stays alive — cancelling is a separate,
     * explicit action on the Instances page.
     */
    public function releaseInstance(Connection $connection): void
    {
        ApiwayInstance::where('connection_id', $connection->id)->update(['connection_id' => null]);
    }
}
