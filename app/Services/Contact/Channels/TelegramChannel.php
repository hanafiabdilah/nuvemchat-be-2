<?php

namespace App\Services\Contact\Channels;

use App\Models\Connection;
use App\Models\Contact;
use App\Services\Contact\ContactChannelInterface;

class TelegramChannel implements ContactChannelInterface
{
    public function addContact(Connection $connection, array $data): Contact
    {
        // TODO: Implement Telegram contact creation
        throw new \Exception('Telegram contact creation not implemented yet');
    }
}
