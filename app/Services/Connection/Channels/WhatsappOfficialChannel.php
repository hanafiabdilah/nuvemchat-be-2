<?php

namespace App\Services\Connection\Channels;

use App\Enums\Connection\Status;
use App\Models\Connection;
use App\Services\Connection\ChannelInterface;
use Illuminate\Support\Facades\Http;

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
        validator($data, [
            'phone_number_id' => ['required', 'string'],
            'access_token' => ['required', 'string'],
        ])->validate();

        $response = Http::get('https://graph.facebook.com/me?access_token=' . $data['access_token']);

        if(!$response->successful()) {
            throw new \Exception('Invalid Access Token provided.', 401);
        }

        $connection->update([
            'status' => Status::Active,
            'credentials' => [
                'phone_number_id' => $data['phone_number_id'],
                'access_token' => $data['access_token'],
            ],
        ]);
    }

    public function disconnect()
    {

    }
}
