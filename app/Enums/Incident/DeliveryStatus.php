<?php

namespace App\Enums\Incident;

/**
 * One conversation's outcome for one incident.
 *
 * `not_matched` is a decision, not a failure: the customer's first message
 * during the incident was about something else, and the row is what keeps
 * their later messages from being judged again. `skipped` means we chose not
 * to send (window closed, opted out, the incident ended first); `failed` means
 * the channel refused.
 */
enum DeliveryStatus: string
{
    case Pending = 'pending';

    /** Claimed by a send in flight. One that stays here lost its worker. */
    case Sending = 'sending';

    case Sent = 'sent';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case NotMatched = 'not_matched';
}
