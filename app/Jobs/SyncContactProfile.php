<?php

namespace App\Jobs;

use App\Models\Connection;
use App\Models\Contact;
use App\Services\Contact\Profile\ContactProfileSyncer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reads one contact's name/username off the ingest path.
 *
 * Same split as SyncContactPhoto: message handlers only decide *whether* a
 * lookup is due, and the HTTP round-trip runs here — never inside the
 * transaction that stores the message.
 */
class SyncContactProfile implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 60;

    /**
     * NB: the channel connection cannot be called $connection — the Queueable
     * trait already owns that property for the queue connection name, and
     * redeclaring it makes the job dispatch onto a nonexistent queue.
     */
    public function __construct(
        public Contact $contact,
        public Connection $channelConnection,
    ) {}

    /** One in-flight lookup per contact; a burst of inbound messages collapses into it. */
    public function uniqueId(): string
    {
        return 'contact-profile:' . $this->contact->id;
    }

    /**
     * Queue a lookup only for a contact still going by its channel id.
     * Dispatched after commit so the worker cannot read the contact before the
     * ingest transaction that created it has landed.
     */
    public static function dispatchIfUnresolved(Contact $contact, Connection $connection): void
    {
        if (! ContactProfileSyncer::needsSync($contact)) {
            return;
        }

        self::dispatch($contact, $connection)->afterCommit();
    }

    public function handle(ContactProfileSyncer $syncer): void
    {
        $syncer->sync($this->contact, $this->channelConnection);
    }
}
