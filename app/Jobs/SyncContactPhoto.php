<?php

namespace App\Jobs;

use App\Models\Connection;
use App\Models\Contact;
use App\Services\Contact\Photo\ContactPhotoSyncer;
use App\Services\Contact\Photo\PhotoHttp;
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

    /**
     * Derived from the network budget, never written by hand.
     *
     * ⚠️ A job that hits its timeout takes the whole `queue:work` process with
     * it — Laravel cannot safely resume past the alarm — so every job still
     * waiting behind it is stalled until the container comes back, about a
     * minute. This job did exactly that twice on 26 Sep 2026, and what it
     * stalled was a flow's 3-second pause, which then arrived 63 seconds late.
     * The cause was arithmetic: the lookups and the download could add up to
     * 90s against a hand-written 60. Keep the two tied together.
     */
    public int $timeout = PhotoHttp::BUDGET + PhotoHttp::OVERHEAD;

    /**
     * NB: the channel connection cannot be called $connection — the Queueable
     * trait already owns that property for the queue connection name, and
     * redeclaring it makes the job dispatch onto a nonexistent queue.
     */
    public function __construct(
        public Contact $contact,
        public Connection $channelConnection,
    ) {
        // Off the default queue. A profile picture is worth having and worth
        // nothing urgently, while `default` is where a flow's next bubble and an
        // AI turn wait — and this job is network-bound against whichever channel
        // is slow today. It belongs with the other heavy, patient, non-urgent
        // media work; with MEDIA_QUEUE unset that is still `default`, so this
        // needs no deployment step to be correct.
        $this->onQueue(config('queue.media'));
    }

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
