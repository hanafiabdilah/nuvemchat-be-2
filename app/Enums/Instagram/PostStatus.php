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

    /** Whether a publish attempt may still be started from here. */
    public function isPublishable(): bool
    {
        return in_array($this, [self::Draft, self::Scheduled, self::Failed], true);
    }
}
