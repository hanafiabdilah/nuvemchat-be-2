<?php

namespace App\Enums\Youtube;

/**
 * Lifecycle of a video *we* hold a record of.
 *
 * Shorter than Instagram's, and the missing state is the point: there is no
 * "scheduled" here. YouTube schedules natively, so a scheduled video is uploaded
 * at once as private with `publishAt` and YouTube makes it public on time — from
 * the moment the upload lands it is a YouTube video, read and managed through
 * the YouTube API like any other, and this row is only its receipt.
 */
enum PostStatus: string
{
    /** Composed, not sent. May have no video yet. */
    case Draft = 'draft';

    /**
     * Handed to the queue, no worker yet. Exists for the same reason as on
     * Instagram: the response must not say "draft" for something that was
     * just sent, and a second press must not queue it twice.
     */
    case Queued = 'queued';

    /** Claimed by PublishYoutubePost — bytes are going up. */
    case Uploading = 'uploading';

    /** On YouTube (public, unlisted, private, or private until `publishAt`). */
    case Published = 'published';

    /** The upload was refused or gave up. `error` holds the reason. */
    case Failed = 'failed';

    /** Statuses whose row is still the user's to edit or delete. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Failed], true);
    }

    /** Whether an upload may still be started from here. */
    public function isPublishable(): bool
    {
        return in_array($this, [self::Draft, self::Failed], true);
    }

    public function isInFlight(): bool
    {
        return in_array($this, [self::Queued, self::Uploading], true);
    }

    /** The rows the feed pins above what is live. */
    public static function pending(): array
    {
        return [self::Draft, self::Queued, self::Uploading, self::Failed];
    }
}
