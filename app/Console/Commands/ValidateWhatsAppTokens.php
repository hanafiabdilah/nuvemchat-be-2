<?php

namespace App\Console\Commands;

use App\Services\Connection\WhatsAppTokenValidator;
use Illuminate\Console\Command;

class ValidateWhatsAppTokens extends Command
{
    protected $signature = 'whatsapp:validate-tokens';

    protected $description = 'Validate stored WhatsApp Cloud API tokens via Meta debug_token; mark connections with revoked tokens as Inactive.';

    public function handle(WhatsAppTokenValidator $validator): int
    {
        $this->info('Validating WhatsApp Cloud API access tokens via Meta debug_token...');

        $deauthorized = $validator->deauthorizeRevoked();

        $this->info("Deauthorized {$deauthorized->count()} connection(s) with revoked tokens.");

        foreach ($deauthorized as $connection) {
            $this->line("  - connection #{$connection->id} (phone_number_id: " . ($connection->credentials['phone_number_id'] ?? 'unknown') . ')');
        }

        return self::SUCCESS;
    }
}
