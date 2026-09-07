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
use App\Support\Errors\UpstreamError;
use App\Support\Errors\UpstreamProvider;
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
            // Already linked: connect only refreshes status/QR. Pointing this
            // connection at a *different* instance is switchInstance() — a
            // separate, confirmed action, never a side effect of pressing the
            // button that reloads a QR code.
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
     * Point this connection at a different purchased instance.
     *
     * Two situations, one operation: the instance behind a connection was
     * cancelled or expired and the tenant bought a replacement, or they simply
     * want this inbox served by another number. Everything that makes the
     * connection what it is — its conversations, flow, agents, automated
     * messages, tags — belongs to the Connection row and stays put; only the
     * WhatsApp session underneath changes.
     *
     * The instance being left behind is logged out of WhatsApp first. It goes
     * back to the pool, and a pooled instance still holding somebody's session
     * is a number that keeps receiving messages nobody reads. That call is
     * best-effort: an instance whose subscription was revoked no longer
     * answers its own token, and that is the most common reason for asking for
     * a swap in the first place — it must not block one.
     */
    public function switchInstance(Connection $connection, int $apiwayInstanceId): void
    {
        $current = (int) ($connection->credentials['apiway_instance_id'] ?? 0);

        if ($current === $apiwayInstanceId) {
            throw new AppConnectionException('Esta conexão já usa essa instância.', 422);
        }

        $this->logoutInstance($connection);

        // linkInstance() releases whatever this connection was holding — it has
        // to, because apiway_instances.connection_id is unique.
        $this->linkInstance($connection, ['apiway_instance_id' => $apiwayInstanceId]);
        $this->registerWebhook($connection);
        $this->retrieveQrCode($connection);
    }

    /**
     * Cut a connection loose from an instance that stopped existing — the
     * subscription behind it was cancelled or ran out.
     *
     * The credentials are moved aside rather than left in place or silently
     * blanked. Left in place, the connect screen keeps offering a QR code that
     * can never pair and the channel keeps a token that opens nothing. Blanked,
     * an inbox stops working with no record of why. What stays behind says
     * "this had an instance, here is which one and what happened to it", which
     * is also what the wizard needs to explain itself and offer a replacement.
     */
    public static function releaseCredentials(Connection $connection, string $reason): void
    {
        $credentials = $connection->credentials ?? [];

        if (! isset($credentials['instance_id']) && ! isset($credentials['apiway_instance_id'])) {
            return;
        }

        $connection->update([
            'credentials' => [
                ...array_intersect_key($credentials, array_flip(['is_managed', 'import_history'])),
                'released_instance' => [
                    'instance_id' => $credentials['instance_id'] ?? null,
                    'apiway_instance_id' => $credentials['apiway_instance_id'] ?? null,
                    // The number is kept for recognition only ("this was the
                    // line for +55…"), never for sending: nothing here is
                    // paired any more.
                    'phone_number' => $credentials['phone_number'] ?? null,
                    'reason' => $reason,
                    'released_at' => now()->toIso8601String(),
                ],
            ],
        ]);
    }

    /**
     * Log the currently linked instance out of WhatsApp. Never throws: every
     * caller is already committed to letting this instance go.
     */
    private function logoutInstance(Connection $connection): void
    {
        if (! isset($connection->credentials['token'], $connection->credentials['instance_id'])) {
            return;
        }

        try {
            Http::withHeaders([
                'Authorization' => 'Bearer ' . $connection->credentials['token'],
            ])->connectTimeout(15)
                ->timeout(20)
                ->get($this->base() . '/v1/instance/disconnect?instanceId=' . $connection->credentials['instance_id']);
        } catch (\Throwable $th) {
            Log::warning('API Way logout failed, continuing anyway', [
                'connection' => $connection->id,
                'error' => $th->getMessage(),
            ]);
        }
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

        // The import opt-in is a preference about this connection, not about
        // whichever instance happens to serve it, so it survives a swap.
        // Everything else in the old credentials belongs to the instance being
        // left behind (its token, its QR, its paired number, the record of an
        // import that already ran) and must not outlive it.
        $keep = array_intersect_key($connection->credentials ?? [], array_flip(['import_history']));

        DB::transaction(function () use ($connection, $instance, $token, $keep) {
            // apiway_instances.connection_id is unique, so claiming an instance
            // for this connection means letting go of whatever it held before.
            // Released by connection_id rather than by the stored credential: a
            // revoked subscription has already nulled it, and a stale credential
            // pointing at somebody else's row must never unlink theirs.
            ApiwayInstance::where('connection_id', $connection->id)
                ->whereKeyNot($instance->id)
                ->update(['connection_id' => null]);

            $instance->update(['connection_id' => $connection->id]);

            $connection->update([
                'status' => Status::Pending,
                'credentials' => [
                    ...$keep,
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
        // `throw: false` is load-bearing. retry() throws a RequestException of
        // its own once the attempts run out, which sails straight past the
        // failure branch below — the only place that logs the core's answer and
        // turns it into a message a human can act on. The controller has no
        // catch for that class either, so the whole thing used to surface as a
        // bare 500 "Failed to run connection" with nothing in the log.
        $qr = Http::withHeaders([
            'Authorization' => 'Bearer ' . $connection->credentials['token'],
        ])->connectTimeout(15)
            ->timeout(30)
            ->retry(3, 800, throw: false)
            ->get($this->base() . '/v1/instance/qr-code?instanceId=' . $connection->credentials['instance_id']);

        $qrJson = $qr->json();

        if ($qr->failed()) {
            Log::error('WhatsApp API Way QR request failed', ['connection' => $connection->id, 'response' => $qrJson, 'status' => $qr->status()]);
            throw new AppConnectionException($this->qrFailureMessage($qrJson), $qr->status() ?: 500);
        }

        // API Way wraps instance responses as { success, data: { qrcode: <data URI> } }.
        $qrCode = $qrJson['data']['qrcode']
            ?? $qrJson['qrcode'] ?? $qrJson['qrCode'] ?? $qrJson['value'] ?? null;

        $connection->update([
            'credentials' => array_merge($connection->credentials, ['qr_code' => $qrCode]),
        ]);
    }

    /**
     * Turn the core's refusal into something the person staring at the empty
     * QR box can act on.
     *
     * `node_error / "not connected"` is the one that matters: the session has
     * no node behind it, which in practice means the instance is no longer
     * provisioned at ProxyBR — a QR will never appear no matter how many times
     * the button is pressed. Saying "not connected" to a business owner sends
     * them to check their phone and their wifi, which is the one place the
     * problem is not.
     *
     * Anything unrecognised gets our vaguest honest sentence rather than the
     * core's own wording. The core answers in the vocabulary of whoever runs
     * it — a business owner staring at an empty QR box cannot act on
     * "node_error" or on a Go error string, and printing one turns a five-word
     * failure into a support ticket. The raw text is in the log line above and
     * in the reference UpstreamError mints.
     */
    private function qrFailureMessage(mixed $body): string
    {
        $error = is_array($body) ? ($body['error'] ?? null) : null;
        $message = is_array($body) ? ($body['message'] ?? null) : null;

        if ($error === 'node_error' || $message === 'not connected') {
            return 'A instância não está ativa no provedor, então nenhum QR Code pode ser gerado. '
                . 'Verifique a assinatura desta instância ou troque a conexão para outra instância.';
        }

        return UpstreamError::message(
            UpstreamProvider::ApiwayCore,
            $message,
            upstreamCode: is_string($error) ? $error : null,
            context: ['operation' => 'qr-code'],
        );
    }

    private function checkInstanceStatus(Connection $connection): Connection
    {
        // See retrieveQrCode(): retry() throwing would skip the branch below.
        $status = Http::withHeaders([
            'Authorization' => 'Bearer ' . $connection->credentials['token'],
        ])->connectTimeout(15)
            ->timeout(30)
            ->retry(3, 800, throw: false)
            ->get($this->base() . '/v1/instance/status-instance?instanceId=' . $connection->credentials['instance_id']);

        $statusJson = $status->json();

        if ($status->failed()) {
            Log::error('WhatsApp API Way status request failed', ['connection' => $connection->id, 'response' => $statusJson, 'status' => $status->status()]);
            throw new AppConnectionException(
                UpstreamError::message(
                    UpstreamProvider::ApiwayCore,
                    is_array($statusJson) ? ($statusJson['message'] ?? null) : null,
                    upstreamCode: is_array($statusJson) && is_string($statusJson['error'] ?? null) ? $statusJson['error'] : null,
                    status: $status->status(),
                    context: ['connection_id' => $connection->id, 'operation' => 'status-instance'],
                ),
                $status->status() ?: 500,
            );
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
        $this->logoutInstance($connection);

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
