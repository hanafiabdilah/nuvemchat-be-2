<?php

namespace App\Console\Commands;

use App\Services\Notification\NotificationProviderFactory;
use Illuminate\Console\Command;

class WhatsappNotificationTest extends Command
{
    protected $signature = 'whatsapp:test {phone : Destination number in digits, e.g. 6282122787699}
                                          {--message=Tes Pingly ✅ : The message body to send}';

    protected $description = 'Send a test WhatsApp message via the configured notification provider and report the result/timing.';

    public function handle(NotificationProviderFactory $factory): int
    {
        $provider = $factory->make();

        $this->line('Provider:   ' . $provider->key());
        $this->line('Configured: ' . ($provider->isConfigured() ? 'yes' : 'no'));

        if (! $provider->isConfigured()) {
            $this->error('Provider is not configured — set its credentials in Back Office → Integrations → Notifications.');

            return self::FAILURE;
        }

        $phone = preg_replace('/\D+/', '', (string) $this->argument('phone'));
        $this->line('Sending to: ' . $phone);

        $start = microtime(true);

        try {
            $provider->send($phone, (string) $this->option('message'));
            $ms = (int) round((microtime(true) - $start) * 1000);
            $this->info("OK — sent in {$ms}ms.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $ms = (int) round((microtime(true) - $start) * 1000);
            $this->error("FAILED after {$ms}ms: " . $e->getMessage());

            return self::FAILURE;
        }
    }
}
