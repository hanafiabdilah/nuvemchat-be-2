<?php

namespace App\Jobs;

use App\Models\Connection;
use App\Services\Email\EmailInboxSynchronizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Pulls one batch of mail for a single email connection, off the request path
 * so the "sync now" button returns immediately.
 *
 * Unique per connection: the scheduler dispatches every minute and a user can
 * also press sync, but a mailbox must never be walked by two workers at once
 * (they would fight over connections.last_seen_uid).
 */
class SyncEmailInbox implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** IMAP is slow and flaky; one retry, then leave it to the next schedule tick. */
    public int $tries = 2;

    public int $timeout = 280;

    /**
     * Cap on the uniqueness lock. Must exceed $timeout so a killed worker
     * cannot leave the connection unsyncable, but stay short enough that the
     * next minute's tick can pick the work back up.
     */
    public int $uniqueFor = 300;

    public function __construct(
        public int $connectionId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->connectionId;
    }

    public function handle(EmailInboxSynchronizer $synchronizer): void
    {
        $connection = Connection::find($this->connectionId);

        if (! $connection) {
            return;
        }

        $synchronizer->sync($connection);
    }
}
