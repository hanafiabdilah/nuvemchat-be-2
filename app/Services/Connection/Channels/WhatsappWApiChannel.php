<?php

namespace App\Services\Connection\Channels;

use App\Models\Connection;
use App\Services\Connection\ChannelInterface;

class WhatsappWApiChannel implements ChannelInterface
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
        //
    }

    public function disconnect()
    {
        //
    }
}
