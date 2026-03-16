<?php

namespace App\Services\Connection\Channels;

use App\Enums\Connection\Status;
use App\Models\Connection;
use App\Services\Connection\ChannelInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
            'instance_id' => ['required', 'string'],
            'token' => ['required', 'string'],
        ])->validate();

        $connection->update([
            'status' => Status::Pending,
            'credentials' => [
                'instance_id' => $data['instance_id'],
                'token' => $data['token'],
            ],
        ]);

        // check status
        $status = Http::withHeaders([
            'Authorization' => 'Bearer ' . $data['token'],
        ])->get('https://api.w-api.app/v1/instance/status-instance?instanceId=' . $connection->credentials['instance_id'])->json();

        Log::info('Whatsapp WApi status response', ['response' => $status]);

        // setup webhhook

        // if status oke, update connection to active and save credentials, and return
        if($status['connected'] === true){
            $connection->update([
                'status' => Status::Active,
            ]);

            return;
        }

        // generate qr


        // update connection
    }

    public function disconnect()
    {
        //
    }
}
