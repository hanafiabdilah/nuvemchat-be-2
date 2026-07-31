<?php

namespace App\Services\Contact\Channels;

use App\Models\Connection;
use App\Models\Contact;
use App\Services\Contact\ContactChannelInterface;

class TikTokChannel implements ContactChannelInterface
{
    public function addContact(Connection $connection, array $data): Contact
    {
        // TikTok users can only be contacted after they message first (48h reply
        // window), so contacts are created from inbound webhooks, never manually.
        throw new \Exception('TikTok contact creation not supported');
    }
}
