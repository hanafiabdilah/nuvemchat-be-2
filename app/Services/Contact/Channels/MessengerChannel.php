<?php

namespace App\Services\Contact\Channels;

use App\Models\Connection;
use App\Models\Contact;
use App\Services\Contact\ContactChannelInterface;

class MessengerChannel implements ContactChannelInterface
{
    public function addContact(Connection $connection, array $data): Contact
    {
        // Messenger does not allow initiating a conversation with an arbitrary
        // user: the Send API only accepts PSIDs of people who already messaged
        // the Page (24h window). Same platform limitation as Instagram.
        throw new \Exception('Messenger contacts cannot be created manually — the user must message the Page first.');
    }
}
