<?php

namespace App\Console\Commands;

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Jobs\SyncEmailInbox;
use App\Models\Connection;
use App\Services\Email\EmailInboxSynchronizer;
use Illuminate\Console\Command;

class FetchEmails extends Command
{
    protected $signature = 'email:fetch {--sync : Run inline instead of queueing (for debugging)}';

    protected $description = 'Queue an inbound-mail sync for every active email connection';

    public function handle(EmailInboxSynchronizer $synchronizer): int
    {
        $connections = Connection::where('channel', Channel::Email)
            ->where('status', ConnectionStatus::Active)
            ->get();

        if ($connections->isEmpty()) {
            $this->info('No active email connections found.');

            return Command::SUCCESS;
        }

        $queued = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($connections as $connection) {
            // A pass already in flight owns the cursor; queueing another would
            // just contend for the same lock.
            if ($synchronizer->isSyncing($connection)) {
                $skipped++;

                continue;
            }

            try {
                if ($this->option('sync')) {
                    // The synchronizer rethrows so the queued job can retry;
                    // inline, one unreachable mailbox must not stop the others.
                    $synchronizer->sync($connection);
                } else {
                    SyncEmailInbox::dispatch($connection->id);
                }

                $queued++;
            } catch (\Throwable $exception) {
                $failed++;
                $this->error("Connection #{$connection->id}: {$exception->getMessage()}");
            }
        }

        $this->info("Queued {$queued} inbox sync(s); {$skipped} already running; {$failed} failed.");

        return Command::SUCCESS;
    }
}
