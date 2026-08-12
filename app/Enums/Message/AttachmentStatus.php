<?php

namespace App\Enums\Message;

/**
 * Where a message's media stands while it is being fetched off the channel.
 *
 * Null is the resting state and means one of two things that never need to be
 * told apart: the message has no media at all, or its media is already on disk
 * (`attachment` says which). Only a message whose bytes are still travelling —
 * or that gave up on them — carries a value here.
 */
enum AttachmentStatus: string
{
    /** Queued for download; the SPA draws a placeholder bubble meanwhile. */
    case Pending = 'pending';

    /** The channel never gave us the bytes; the placeholder becomes an error. */
    case Failed = 'failed';
}
