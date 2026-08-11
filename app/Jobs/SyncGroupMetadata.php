<?php

namespace App\Jobs;

use App\Models\Connection;
use App\Models\Contact;
use App\Services\Conversation\Group\GroupMetadataSyncer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Re-reads one group's subject off the ingest path.
 *
 * Mirrors SyncContactPhoto: the message handler only decides *whether* a lookup
 * is due, the HTTP round-trip happens here, outside the transaction that stores
 * the message.
 */
class SyncGroupMetadata implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 60;

    /**
     * NB: the channel connection cannot be called $connection — the Queueable
     * trait already owns that property for the queue connection name.
     */
    public function __construct(
        public Contact $contact,
        public Connection $channelConnection,
    ) {}

    /** One in-flight lookup per group; a burst of messages collapses into it. */
    public function uniqueId(): string
    {
        return 'group-metadata:' . $this->contact->id;
    }

    /**
     * Queue a lookup only when the stored subject is past its TTL (or was never
     * read). Dispatched after commit so the worker cannot read the contact
     * before the ingest transaction that created it has landed.
     */
    public static function dispatchIfStale(Contact $contact, Connection $connection): void
    {
        if (! GroupMetadataSyncer::isStale($contact)) {
            return;
        }

        self::dispatch($contact, $connection)->afterCommit();
    }

    public function handle(GroupMetadataSyncer $syncer): void
    {
        $syncer->sync($this->contact, $this->channelConnection);
    }
}
