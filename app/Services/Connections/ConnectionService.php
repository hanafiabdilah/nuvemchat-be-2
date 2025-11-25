<?php

namespace App\Services\Connections;

use App\Models\Connection;
use App\Services\Connections\Factory\ChannelFactory;

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
