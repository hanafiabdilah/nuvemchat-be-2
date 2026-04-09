<?php

namespace App\Services\Contact\Channels;

use App\Models\Connection;
use App\Models\Contact;
use App\Services\Contact\ContactChannelInterface;

class WhatsappOfficialChannel implements ContactChannelInterface
{
    public function addContact(Connection $connection, array $data): Contact
    {
        // TODO: Implement WhatsApp Official API contact creation
        throw new \Exception('WhatsApp Official contact creation not implemented yet');
    }
}
