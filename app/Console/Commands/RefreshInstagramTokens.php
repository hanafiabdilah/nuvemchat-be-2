<?php

namespace App\Console\Commands;

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status;
use App\Models\Connection;
use App\Services\Connection\Channels\InstagramChannel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RefreshInstagramTokens extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'instagram:refresh-tokens
                            {--days-before=7 : Refresh tokens expiring within this many days}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh Instagram long-lived access tokens that are about to expire';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $daysBefore = $this->option('days-before');
        $expiryThreshold = now()->addDays($daysBefore);

        $this->info("Checking Instagram connections expiring before {$expiryThreshold->toDateTimeString()}...");

        // Get all active Instagram connections
        $connections = Connection::where('channel', Channel::Instagram)
            ->where('status', Status::Active)
            ->get();

        if ($connections->isEmpty()) {
            $this->info('No active Instagram connections found.');
            return Command::SUCCESS;
        }

        $refreshed = 0;
        $failed = 0;
        $skipped = 0;

        $instagramChannel = new InstagramChannel();

        foreach ($connections as $connection) {
            $tokenExpiresAt = $connection->credentials['token_expires_at'] ?? null;

            if (!$tokenExpiresAt) {
                $this->warn("Connection #{$connection->id} ({$connection->name}): No expiry date found, skipping");
                $skipped++;
                continue;
            }

            $expiryDate = \Carbon\Carbon::parse($tokenExpiresAt);

            // Skip if token is not expiring soon
            if ($expiryDate->isAfter($expiryThreshold)) {
                $daysRemaining = now()->diffInDays($expiryDate);
                $this->info("Connection #{$connection->id} ({$connection->name}): Token expires in {$daysRemaining} days, skipping");
                $skipped++;
                continue;
            }

            // Refresh the token
            try {
                $this->info("Connection #{$connection->id} ({$connection->name}): Refreshing token...");
                $instagramChannel->refreshToken($connection);
                $this->info("✓ Connection #{$connection->id} ({$connection->name}): Token refreshed successfully");
                $refreshed++;
            } catch (\Throwable $th) {
                $this->error("✗ Connection #{$connection->id} ({$connection->name}): Failed to refresh - {$th->getMessage()}");
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Summary:");
        $this->info("  Total connections: {$connections->count()}");
        $this->info("  ✓ Refreshed: {$refreshed}");
        $this->info("  ✗ Failed: {$failed}");
        $this->info("  ⊝ Skipped: {$skipped}");

        Log::info('Instagram token refresh completed', [
            'total' => $connections->count(),
            'refreshed' => $refreshed,
            'failed' => $failed,
            'skipped' => $skipped,
        ]);

        return Command::SUCCESS;
    }
}
