<?php

namespace App\Enums\Message;

/**
 * Where a message's media stands while it is being fetched off the channel —
 * and, at the other end of its life, once we stop paying to keep it.
 *
 * Null is the resting state and means one of two things that never need to be
 * told apart: the message has no media at all, or its media is already on disk
 * (`attachment` says which). Only a message whose bytes are still travelling,
 * that gave up on them, or whose file we have since deleted carries a value.
 */
enum AttachmentStatus: string
{
    /** Queued for download; the SPA draws a placeholder bubble meanwhile. */
    case Pending = 'pending';

    /** The channel never gave us the bytes; the placeholder becomes an error. */
    case Failed = 'failed';

    /**
     * The file outlived its retention window and `media:purge` deleted it.
     * The message, its caption and its place in the thread are untouched —
     * only the bubble's contents are gone. See App\Services\Media\MediaRetention.
     */
    case Expired = 'expired';
}
