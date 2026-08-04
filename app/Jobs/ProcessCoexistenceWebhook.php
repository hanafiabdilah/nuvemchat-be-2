<?php

namespace App\Jobs;

use App\Models\Connection;
use App\Services\Webhook\Handlers\Chat\WhatsappCoexistenceHandler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Ingest one heavy Coexistence webhook chunk (`history` or
 * `smb_app_state_sync`) off the request path. A single history chunk can
 * carry hundreds of messages, so the webhook controller queues the raw
 * `value` here and answers Meta 200 immediately.
 *
 * Dedupe lives in the handler (per-conversation external_id / firstOrCreate
 * on contacts), so a retried or duplicated webhook never double-imports.
 */
class ProcessCoexistenceWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 1;

    public function __construct(
        public int $connectionId,
        public string $field,
        public array $value,
    ) {
    }

    public function handle(WhatsappCoexistenceHandler $handler): void
    {
        $connection = Connection::find($this->connectionId);

        if (!$connection) {
            Log::warning('ProcessCoexistenceWebhook: connection no longer exists', [
                'connection_id' => $this->connectionId,
                'field' => $this->field,
            ]);
            return;
        }

        match ($this->field) {
            'history' => $handler->ingestHistoryChunk($connection, $this->value),
            'smb_app_state_sync' => $handler->ingestStateSync($connection, $this->value),
            default => Log::warning('ProcessCoexistenceWebhook: unsupported field', [
                'connection_id' => $this->connectionId,
                'field' => $this->field,
            ]),
        };
    }
}
