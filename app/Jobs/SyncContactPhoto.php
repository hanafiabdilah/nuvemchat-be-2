<?php

namespace App\Jobs;

use App\Models\Connection;
use App\Models\Contact;
use App\Services\Contact\Photo\ContactPhotoSyncer;
use App\Services\Contact\Photo\PhotoResolverFactory;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Re-reads one contact's profile picture off the ingest path.
 *
 * Message handlers only decide *whether* a lookup is due; the HTTP round-trips
 * belong here, outside the transaction that stores the message.
 */
class SyncContactPhoto implements ShouldBeUnique, ShouldQueue
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

    /** One in-flight lookup per contact; bursts from a busy group collapse into it. */
    public function uniqueId(): string
    {
        return 'contact-photo:' . $this->contact->id;
    }

    /**
     * Queue a lookup only when the stored photo is past its TTL (or missing).
     * Dispatched after commit so the worker cannot read the contact before the
     * ingest transaction that created it has landed.
     */
    public static function dispatchIfStale(Contact $contact, Connection $connection): void
    {
        if (! ContactPhotoSyncer::isStale($contact)) {
            return;
        }

        self::dispatch($contact, $connection)->afterCommit();
    }

    /**
     * Queue a lookup regardless of the TTL — for the moments a channel tells us
     * outright that the picture changed (Telegram new_chat_photo, say).
     */
    public static function dispatchForced(Contact $contact, Connection $connection): void
    {
        if (! PhotoResolverFactory::supports($contact->channel)) {
            return;
        }

        self::dispatch($contact, $connection)->afterCommit();
    }

    public function handle(ContactPhotoSyncer $syncer): void
    {
        $syncer->sync($this->contact, $this->channelConnection);
    }
}
