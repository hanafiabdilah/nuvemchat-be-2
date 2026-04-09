<?php

namespace App\Services\Contact\Channels;

use App\Models\Connection;
use App\Models\Contact;
use App\Services\Contact\ContactChannelInterface;

class InstagramChannel implements ContactChannelInterface
{
    public function addContact(Connection $connection, array $data): Contact
    {
        // TODO: Implement Instagram contact creation
        throw new \Exception('Instagram contact creation not implemented yet');
    }
}
