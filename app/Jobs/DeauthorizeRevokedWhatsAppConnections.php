<?php

namespace App\Jobs;

use App\Services\Connection\WhatsAppTokenValidator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Runs the WhatsApp revoked-token scan off the request path.
 *
 * Dispatched by the Meta deauthorization webhook when the fallback scan would
 * have to probe more connections than we can validate inline (each connection
 * costs one Graph API round-trip). Keeping the loop out of the synchronous
 * request avoids timing out Meta's callback on large tenants.
 */
class DeauthorizeRevokedWhatsAppConnections implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Give the scan room to run; it makes one Graph call per connection. */
    public int $timeout = 600;

    public function handle(WhatsAppTokenValidator $validator): void
    {
        $deauthorized = $validator->deauthorizeRevoked();

        Log::info('Async WhatsApp revoked-token deauthorization complete', [
            'deauthorized_count' => $deauthorized->count(),
            'connection_ids' => $deauthorized->pluck('id')->all(),
        ]);
    }
}
