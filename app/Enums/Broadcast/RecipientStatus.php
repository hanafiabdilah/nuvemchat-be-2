<?php

namespace App\Enums\Broadcast;

/**
 * One recipient's outcome.
 *
 * `skipped` is deliberately distinct from `failed`: it means the platform was
 * never asked (the session window had shut, the contact opted out, the campaign
 * was canceled first). Collapsing the two would hide the difference between
 * "we chose not to" and "they refused us", which is the first thing an operator
 * looks at when a campaign under-delivers.
 */
enum RecipientStatus: string
{
    case Pending = 'pending';

    /** Claimed by a running batch; reset by the watchdog if it goes stale. */
    case Sending = 'sending';

    case Sent = 'sent';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
