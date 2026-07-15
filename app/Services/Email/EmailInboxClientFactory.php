<?php

namespace App\Services\Email;

use App\Models\Connection;

interface EmailInboxClientFactory
{
    public function make(Connection $connection): EmailInboxClient;
}
