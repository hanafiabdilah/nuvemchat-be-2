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
        ])->get('https://api.w-api.app/v1/instance/status-instance?instanceId=' . $connection->credentials['instance_id']);
        $statusJson = $status->json();

        Log::info('Whatsapp WApi status response', ['response' => $statusJson, 'status code' => $status->status()]);

        // setup webhhook

        if($statusJson['connected'] === true){
            $connection->update([
                'status' => Status::Active,
            ]);

            return;
        }

        // generate qr
        $qr = Http::withHeaders([
            'Authorization' => 'Bearer ' . $data['token'],
        ])->get('https://api.w-api.app/v1/instance/qr-code?image=disable&instanceId=' . $connection->credentials['instance_id']);
        $qrJson = $qr->json();

        Log::info('Whatsapp WApi QR response', ['response' => $qrJson, 'status code' => $qr->status()]);

        $connection->update([
            'credentials' => array_merge($connection->credentials, [
                'qr_code' => $qrJson['qrcode'],
            ]),
        ]);
    }

    public function disconnect()
    {
        //
    }
}
