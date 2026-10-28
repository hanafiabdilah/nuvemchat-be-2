<?php

namespace App\Services\Youtube;

use App\Models\YoutubeAccount;

/**
 * Builds a Data API client for a channel. A factory rather than `new` at the
 * call sites so tests can swap the Google side out in one bind.
 */
class YoutubeClientFactory
{
    public function __construct(private readonly YoutubeOAuth $oauth) {}

    public function for(YoutubeAccount $account): YoutubeClient
    {
        return new YoutubeClient($account, $this->oauth);
    }
}
