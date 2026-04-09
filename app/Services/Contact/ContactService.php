<?php

namespace App\Services\Contact;

use App\Models\Connection;
use App\Models\Contact;

class ContactService
{
    public function addContact(Connection $connection, array $data): Contact
    {
        $channel = ContactChannelFactory::make($connection->channel);
        return $channel->addContact($connection, $data);
    }
}
