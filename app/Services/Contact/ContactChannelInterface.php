<?php

namespace App\Services\Contact;

use App\Models\Connection;
use App\Models\Contact;

interface ContactChannelInterface
{
    /**
     * Add a new contact
     */
    public function addContact(Connection $connection, array $data): Contact;
}
