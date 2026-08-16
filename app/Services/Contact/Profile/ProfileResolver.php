<?php

namespace App\Services\Contact\Profile;

use App\Models\Connection;
use App\Models\Contact;

/**
 * Reads the display name / username of one contact on one channel.
 *
 * Same contract as PhotoResolver, and for the same reason — the two outcomes
 * must stay distinct:
 *   - return null  => the channel answered but has no identity to give.
 *   - throw        => the lookup failed (network, expired token, a permission
 *                     the account does not have yet). The stored name is kept
 *                     and the attempt is backed off, not abandoned.
 */
interface ProfileResolver
{
    public function resolve(Contact $contact, Connection $connection): ?ContactProfile;
}
