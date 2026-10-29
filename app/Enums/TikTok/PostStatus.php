<?php

namespace App\Enums\TikTok;

/**
 * Lifecycle of a TikTok post *we* hold a record of — the same states as
 * Instagram's, for the same reason: TikTok has no draft or schedule of its own
 * on this API, so both are ours to keep.
 *
 * Once Published the row is a receipt. The Accounts API offers neither an edit
 * nor a delete for a live post.
 */
enum PostStatus: string
{
    case Draft = 'draft';

    /** Waiting for `scheduled_at`; `tiktok:publish-scheduled` hands it to the queue. */
    case Scheduled = 'scheduled';

    /** Handed to the queue, no worker yet (see Instagram's PostStatus::Queued). */
    case Queued = 'queued';

    /** Sent to TikTok; it is downloading and moderating it (`share_id` set). */
    case Publishing = 'publishing';

    case Published = 'published';

    /** TikTok refused it or gave up. `error` holds the reason, in our words. */
    case Failed = 'failed';

    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Scheduled, self::Failed], true);
    }

    public function isPublishable(): bool
    {
        return in_array($this, [self::Draft, self::Scheduled, self::Failed], true);
    }

    public function isInFlight(): bool
    {
        return in_array($this, [self::Queued, self::Publishing], true);
    }

    /** The rows the grid pins above what is live. */
    public static function pending(): array
    {
        return [self::Draft, self::Scheduled, self::Queued, self::Publishing, self::Failed];
    }
}
