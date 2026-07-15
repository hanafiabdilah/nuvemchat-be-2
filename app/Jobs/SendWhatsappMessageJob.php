<?php

namespace App\Jobs;

use App\Models\WhatsappMessageLog;
use App\Services\Notification\NotificationProviderFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Delivers a WhatsApp message via the configured provider, off the request path.
 * Dispatched with ->afterResponse() (or onto the queue) so a slow/unreachable
 * provider never blocks the HTTP request that triggered it (e.g. registration).
 * Always records the attempt to whatsapp_message_logs.
 */
class SendWhatsappMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Give up reasonably fast — an OTP that arrives late is useless. */
    public int $tries = 2;
    public int $timeout = 30;

    public function __construct(
        public string $to,
        public string $message,
        public string $type,          // e.g. 'otp' | 'notification:<event>'
        public ?int $userId = null,
    ) {}

    public function handle(NotificationProviderFactory $factory): void
    {
        $provider = $factory->make();

        if (! $provider->isConfigured()) {
            Log::warning('SendWhatsappMessageJob: provider not configured; not sent', [
                'provider' => $provider->key(),
                'type' => $this->type,
            ]);
            WhatsappMessageLog::record($provider->key(), $this->to, $this->type, $this->message, WhatsappMessageLog::STATUS_FAILED, 'provider not configured', $this->userId);

            return;
        }

        try {
            $provider->send($this->to, $this->message);
            WhatsappMessageLog::record($provider->key(), $this->to, $this->type, $this->message, WhatsappMessageLog::STATUS_SENT, null, $this->userId);
        } catch (\Throwable $th) {
            Log::error('SendWhatsappMessageJob: send failed', ['type' => $this->type, 'error' => $th->getMessage()]);
            WhatsappMessageLog::record($provider->key(), $this->to, $this->type, $this->message, WhatsappMessageLog::STATUS_FAILED, $th->getMessage(), $this->userId);
            throw $th; // allow the queue to retry
        }
    }
}
