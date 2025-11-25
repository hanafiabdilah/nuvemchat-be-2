<?php

namespace App\Services\Connection;

use App\Models\Connection;
use App\Services\Connection\ChannelFactory;

class ConnectionService
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
        $channel = ChannelFactory::make($connection->channel);
        $channel->connect($connection, $data);
    }
}
