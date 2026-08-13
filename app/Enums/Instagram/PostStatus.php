<?php

namespace App\Enums\Instagram;

/**
 * Lifecycle of a post *we* own a record of.
 *
 * Note what is missing: there is no status for "live on Instagram and still
 * ours to change". Once Published, the row is a receipt — Instagram owns the
 * post from then on, and the Instagram Login flavour of the API offers neither
 * an edit nor a delete endpoint for media (see InstagramGraphClient).
 */
enum PostStatus: string
{
    /** Composed but not scheduled and not sent. */
    case Draft = 'draft';

    /** Waiting for `scheduled_at`; the scheduler will hand it to the queue. */
    case Scheduled = 'scheduled';

    /**
     * Handed to the queue, but no worker has picked it up yet.
     *
     * Distinct from Publishing so the dashboard can tell the truth in the gap
     * between the button and the worker. Without it, a post sent with "Publish
     * now" went back to the browser still reading Draft — the row only changed
     * once the job ran — which looked like the button had quietly failed. It
     * also stops the scheduler re-dispatching the same post every minute while
     * a backed-up queue works through its backlog.
     */
    case Queued = 'queued';

    /** Claimed by PublishInstagramPost — containers are being built at Meta. */
    case Publishing = 'publishing';

    case Published = 'published';

    /** Meta rejected it, or every retry was used. `error` holds their words. */
    case Failed = 'failed';

    /** A schedule the user called off before it fired. */
    case Cancelled = 'cancelled';

    /** Statuses whose row is still the user's to edit or delete. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Scheduled, self::Failed, self::Cancelled], true);
    }

    /**
     * Whether a publish attempt may still be started from here.
     *
     * Queued is excluded: it is already on its way, and treating it as
     * startable would let a second press of the button put a second copy of the
     * same post into the queue.
     */
    public function isPublishable(): bool
    {
        return in_array($this, [self::Draft, self::Scheduled, self::Failed], true);
    }

    /** On its way out, and not the user's to touch any more. */
    public function isInFlight(): bool
    {
        return in_array($this, [self::Queued, self::Publishing], true);
    }
}
