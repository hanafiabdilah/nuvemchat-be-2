<?php

namespace App\Console\Commands;

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status;
use App\Events\ConnectionUpdated;
use App\Models\Connection;
use App\Services\Connection\Channels\TikTokChannel;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RefreshTikTokTokens extends Command
{
    /**
     * The name and signature of the console command.
     *
     * TikTok access tokens only live ~24h (refresh tokens ~30 days), so this
     * runs hourly and refreshes anything expiring soon — unlike Instagram's
     * daily 60-day-token cadence.
     *
     * @var string
     */
    protected $signature = 'tiktok:refresh-tokens
                            {--minutes-before=120 : Refresh tokens expiring within this many minutes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh TikTok access tokens that are about to expire';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $minutesBefore = (int) $this->option('minutes-before');
        $expiryThreshold = now()->addMinutes($minutesBefore);

        $connections = Connection::where('channel', Channel::TikTok)
            ->where('status', Status::Active)
            ->get();

        if ($connections->isEmpty()) {
            $this->info('No active TikTok connections found.');
            return Command::SUCCESS;
        }

        $refreshed = 0;
        $failed = 0;
        $skipped = 0;
        $expired = 0;

        $tiktokChannel = new TikTokChannel();

        foreach ($connections as $connection) {
            $credentials = $connection->credentials ?? [];
            $tokenExpiresAt = $credentials['token_expires_at'] ?? null;
            $refreshExpiresAt = $credentials['refresh_token_expires_at'] ?? null;

            // A dead refresh token means the account must be re-authorized;
            // flag the connection instead of failing silently every hour.
            if ($refreshExpiresAt && Carbon::parse($refreshExpiresAt)->isPast()) {
                $this->warn("Connection #{$connection->id} ({$connection->name}): Refresh token expired, marking inactive");
                $connection->update(['status' => Status::Inactive]);
                broadcast(new ConnectionUpdated($connection));
                $expired++;
                continue;
            }

            if ($tokenExpiresAt && Carbon::parse($tokenExpiresAt)->isAfter($expiryThreshold)) {
                $skipped++;
                continue;
            }

            try {
                $this->info("Connection #{$connection->id} ({$connection->name}): Refreshing token...");
                $tiktokChannel->refreshToken($connection);
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
        $this->info("  ✝ Expired (needs re-auth): {$expired}");

        Log::info('TikTok token refresh completed', [
            'total' => $connections->count(),
            'refreshed' => $refreshed,
            'failed' => $failed,
            'skipped' => $skipped,
            'expired' => $expired,
        ]);

        return Command::SUCCESS;
    }
}
