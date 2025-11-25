<?php

namespace App\Services\Connections\Channels;

use App\Models\Connection;
use App\Services\Connections\ChannelInterface;

class WhatsappOfficialChannel implements ChannelInterface
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }

    public function connect(Connection $connection, array $data)
    {

    }

    public function disconnect()
    {

    }
}
