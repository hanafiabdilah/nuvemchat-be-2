<?php

namespace App\Services\TikTok;

use App\Models\Connection;

/** Builds an Accounts API client for a connection; a factory so tests can swap TikTok out. */
class TikTokAccountsClientFactory
{
    public function for(Connection $connection): TikTokAccountsClient
    {
        return new TikTokAccountsClient($connection);
    }
}
