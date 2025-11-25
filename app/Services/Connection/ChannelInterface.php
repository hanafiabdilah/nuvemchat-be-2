<?php

namespace App\Services\Connection;

use App\Models\Connection;

interface ChannelInterface
{
    public function connect(Connection $connection, array $data);
    public function disconnect();
}
