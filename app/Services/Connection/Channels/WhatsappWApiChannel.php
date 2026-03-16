<?php

namespace App\Services\Connection\Channels;

use App\Enums\Connection\Status;
use App\Exceptions\ConnectionException;
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

        if($status->failed()){
            Log::error('Whatsapp WApi status request failed', ['connection' => $connection, 'response' => $statusJson, 'status code' => $status->status()]);
            throw new ConnectionException($statusJson['message'] ?? 'Failed to connect to Whatsapp WApi', $status->status());
        }

        $webhookPaths = [
            'update-webhook-connected',
            'update-webhook-disconnected',
            // 'update-webhook-delivery',
            // 'update-webhook-received',
            // 'update-webhook-message-status',
            // 'update-webhook-chat-presence',
        ];
        $webhookResponses = [];

        foreach($webhookPaths as $webhookPath){
            $webhook = Http::withHeaders([
                'Authorization' => 'Bearer ' . $data['token'],
            ])->post('https://api.w-api.app/v1/webhook/' . $webhookPath, [
                'value' => route('webhook.chat', ['id' => $connection->id]),
            ]);
            $webhookJson = $webhook->json();
            $webhookResponses[] = [
                'path' => $webhookPath,
                'response' => $webhookJson,
                'status_code' => $webhook->status(),
            ];
        }

        Log::info('Whatsapp WApi webhooks setup response', ['connection' => $connection, 'responses' => $webhookResponses]);

        if($statusJson['connected'] === true){
            $connection->update([
                'status' => Status::Active,
            ]);

            return;
        }

        $qr = Http::withHeaders([
            'Authorization' => 'Bearer ' . $data['token'],
        ])->get('https://api.w-api.app/v1/instance/qr-code?image=disable&instanceId=' . $connection->credentials['instance_id']);
        $qrJson = $qr->json();

        if($qr->failed()){
            Log::error('Whatsapp WApi QR request failed', ['connection' => $connection, 'response' => $qrJson, 'status code' => $qr->status()]);
            throw new ConnectionException($qrJson['message'] ?? 'Failed to retrieve QR code from Whatsapp WApi', $qr->status());
        }

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
