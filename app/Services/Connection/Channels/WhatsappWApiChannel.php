<?php

namespace App\Services\Connection\Channels;

use App\Enums\Connection\Status;
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
        validator($data, [
            'host' => ['required', 'string'],
            'instance_id' => ['required', 'string'],
            'token' => ['required', 'string'],
        ])->validate();

        $connection->update([
            'status' => Status::Pending,
            'credentials' => [
                'host' => $data['host'],
                'instance_id' => $data['instance_id'],
                'token' => $data['token'],
            ],
        ]);

        // check status


        // setup webhhook
        // if status oke, update connection to active and save credentials, and return


        // generate qr


        // update connection
    }

    public function disconnect()
    {
        //
    }
}
