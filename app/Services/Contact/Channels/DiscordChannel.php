<?php

namespace App\Services\Contact\Channels;

use App\Models\Connection;
use App\Models\Contact;
use App\Services\Contact\ContactChannelInterface;

class DiscordChannel implements ContactChannelInterface
{
    public function addContact(Connection $connection, array $data): Contact
    {
        // A bot can only open a DM with users who share a server with it, and
        // only after Discord hands us their user id — there is no reliable way
        // to start a conversation from a manually-entered identifier.
        throw new \Exception('Discord contacts cannot be created manually — the user must message the bot first.');
    }
}
