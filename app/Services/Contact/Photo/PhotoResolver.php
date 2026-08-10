<?php

namespace App\Services\Contact\Photo;

use App\Models\Connection;
use App\Models\Contact;

/**
 * Locates the current profile picture of one contact on one channel.
 *
 * Implementations must keep two outcomes distinct, because the syncer treats
 * them very differently:
 *   - return null  => the channel confirmed there is no picture. The stored
 *                     one is cleared.
 *   - throw        => the lookup failed (network, expired token, rate limit).
 *                     Whatever we already have is kept and the sync is retried
 *                     on the next pass.
 * Never return null to signal a failed request: it deletes a good photo.
 */
interface PhotoResolver
{
    public function resolve(Contact $contact, Connection $connection): ?PhotoSource;
}
