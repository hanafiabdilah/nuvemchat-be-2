<?php

namespace App\Services\Instagram;

use App\Models\Connection;

/**
 * Builds a Graph client for a connection.
 *
 * A factory rather than `new` at the call sites so tests can swap the whole
 * Instagram side of the feature out in one bind, and so the publisher can stay
 * a plain service with no knowledge of how a client is constructed.
 */
class InstagramGraphClientFactory
{
    public function for(Connection $connection): InstagramGraphClient
    {
        return new InstagramGraphClient($connection);
    }
}
