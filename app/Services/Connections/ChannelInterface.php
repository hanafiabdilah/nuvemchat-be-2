<?php

namespace App\Services\Connections;

use App\Models\Connection;

interface ChannelInterface
{
    public function connect(Connection $connection, array $data);
    public function disconnect();
}
