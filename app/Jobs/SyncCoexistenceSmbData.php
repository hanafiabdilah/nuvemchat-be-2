<?php

namespace App\Jobs;

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Events\ConnectionUpdated;
use App\Models\Connection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Coexistence 24-hour synchronization window: after a WhatsApp Business App
 * number is onboarded to Cloud API, Meta allows exactly ONE request per
 * sync_type on POST /{phone_number_id}/smb_app_data, and only within 24h of
 * onboarding — after that the business must offboard and redo the flow.
 *
 * This job fires both requests (contacts first, then history) right after
 * connect. The actual data does NOT come back in the response: Meta delivers
 * it asynchronously via the `smb_app_state_sync` and `history` webhooks
 * (ingested by WhatsappCoexistenceHandler / ProcessCoexistenceWebhook).
 *
 * One-shot per onboarding: state machine in credentials.smb_data_sync.status
 * (queued → running → requested | failed). "failed" is retryable via a
 * re-connect. Reconnecting rewrites credentials, which resets the state for
 * the new onboarding cycle.
 */
class SyncCoexistenceSmbData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 1;

    public function __construct(public int $connectionId)
    {
    }

    /**
     * Queue the sync when a coexistence connection just became Active and no
     * sync has been requested for this onboarding cycle yet.
     */
    public static function dispatchIfPending(Connection $connection): void
    {
        if ($connection->channel !== Channel::WhatsappOfficial) {
            return;
        }
        if ($connection->status !== ConnectionStatus::Active) {
            return;
        }

        $credentials = $connection->credentials ?? [];
        if (empty($credentials['is_coexistence'])) {
            return;
        }

        $state = $credentials['smb_data_sync']['status'] ?? null;
        if (in_array($state, ['queued', 'running', 'requested'], true)) {
            return;
        }

        $credentials['smb_data_sync'] = [
            'status' => 'queued',
            'queued_at' => now()->toIso8601String(),
        ];
        $connection->update(['credentials' => $credentials]);

        self::dispatch($connection->id);

        Log::info('SyncCoexistenceSmbData: queued', ['connection_id' => $connection->id]);
    }

    public function handle(): void
    {
        // Claim the run under a row lock so a double dispatch results in a
        // single pair of smb_app_data requests (Meta allows each only once).
        $connection = DB::transaction(function () {
            $connection = Connection::lockForUpdate()->find($this->connectionId);

            if (!$connection || ($connection->credentials['smb_data_sync']['status'] ?? null) !== 'queued') {
                return null;
            }

            $this->setState($connection, ['status' => 'running', 'started_at' => now()->toIso8601String()]);

            return $connection;
        });

        if (!$connection) {
            return;
        }

        $phoneNumberId = $connection->credentials['phone_number_id'] ?? null;
        $accessToken = $connection->credentials['access_token'] ?? null;

        if (!$phoneNumberId || !$accessToken) {
            $this->setState($connection, [
                'status' => 'failed',
                'error' => 'Missing phone_number_id/access_token credentials',
                'finished_at' => now()->toIso8601String(),
            ]);
            return;
        }

        $results = [];
        $anyFailed = false;

        // Contacts first, then history — the order Meta documents.
        foreach (['smb_app_state_sync', 'history'] as $syncType) {
            $results[$syncType] = $this->requestSync($phoneNumberId, $accessToken, $syncType);
            $anyFailed = $anyFailed || ($results[$syncType]['ok'] === false);
        }

        $this->setState($connection, [
            'status' => $anyFailed ? 'failed' : 'requested',
            'requests' => $results,
            'error' => $anyFailed
                ? collect($results)->pluck('error')->filter()->implode('; ')
                : null,
            'finished_at' => now()->toIso8601String(),
        ]);

        broadcast(new ConnectionUpdated($connection->fresh()));

        Log::info('SyncCoexistenceSmbData: finished', [
            'connection_id' => $connection->id,
            'results' => $results,
        ]);
    }

    /**
     * @return array{ok: bool, request_id?: string|null, error?: string|null}
     */
    private function requestSync(string $phoneNumberId, string $accessToken, string $syncType): array
    {
        try {
            $response = Http::withToken($accessToken)
                ->post("https://graph.facebook.com/v25.0/{$phoneNumberId}/smb_app_data", [
                    'messaging_product' => 'whatsapp',
                    'sync_type' => $syncType,
                ]);

            if ($response->successful()) {
                return [
                    'ok' => true,
                    'request_id' => $response->json('request_id'),
                ];
            }

            $error = $response->json('error') ?? [];
            $message = (string) ($error['message'] ?? ('HTTP ' . $response->status()));

            // "Already requested" for this onboarding cycle — treat as success
            // (a race between two connect attempts), the webhooks still come.
            if (stripos($message, 'already') !== false) {
                Log::info('SyncCoexistenceSmbData: sync already requested; treating as success', [
                    'phone_number_id' => $phoneNumberId,
                    'sync_type' => $syncType,
                    'error' => $error,
                ]);
                return ['ok' => true, 'request_id' => null];
            }

            Log::error('SyncCoexistenceSmbData: smb_app_data request failed', [
                'phone_number_id' => $phoneNumberId,
                'sync_type' => $syncType,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return ['ok' => false, 'error' => mb_substr($message, 0, 200)];
        } catch (\Throwable $th) {
            Log::error('SyncCoexistenceSmbData: smb_app_data request threw', [
                'phone_number_id' => $phoneNumberId,
                'sync_type' => $syncType,
                'error' => $th->getMessage(),
            ]);

            return ['ok' => false, 'error' => mb_substr($th->getMessage(), 0, 200)];
        }
    }

    /**
     * Merge fields into credentials.smb_data_sync on a fresh copy of the row.
     */
    private function setState(Connection $connection, array $fields): void
    {
        $connection->refresh();
        $credentials = $connection->credentials ?? [];
        $credentials['smb_data_sync'] = array_merge($credentials['smb_data_sync'] ?? [], $fields);
        $connection->update(['credentials' => $credentials]);
    }
}
