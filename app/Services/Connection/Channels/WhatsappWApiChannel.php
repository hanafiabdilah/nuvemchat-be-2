<?php

namespace App\Services\Connection\Channels;

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status;
use App\Exceptions\ConnectionException;
use App\Models\Connection;
use App\Services\Connection\ChannelInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

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

        if(Connection::where('id', '!=', $connection->id)->where('channel', Channel::WhatsappWApi)->where('credentials->instance_id', $data['instance_id'])->exists()) {
            throw ValidationException::withMessages(['instance_id' => 'The instance_id has already been taken.']);
        }

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
            'update-webhook-delivery',
            'update-webhook-received',
            'update-webhook-message-status',
            'update-webhook-chat-presence',
        ];
        $webhookResponses = [];

        foreach($webhookPaths as $webhookPath){
            $endpoint = 'https://api.w-api.app/v1/webhook/' . $webhookPath . '?instanceId=' . $connection->credentials['instance_id'];
            $requestData = [
                'value' => route('webhook.chat', ['id' => $connection->id]),
            ];

            $webhook = Http::withHeaders([
                'Authorization' => 'Bearer ' . $data['token'],
            ])->put($endpoint, $requestData);
            $webhookJson = $webhook->json();

            $webhookResponses[] = [
                'endpoint' => $endpoint,
                'request_data' => $requestData,
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

    public function checkStatus(Connection $connection): void
    {
        try {
            $status = Http::withHeaders([
                'Authorization' => 'Bearer ' . $connection->credentials['token'],
            ])->get('https://api.w-api.app/v1/instance/status-instance?instanceId=' . $connection->credentials['instance_id']);

            $statusJson = $status->json();

            if($status->failed()){
                $connection->update([
                    'status' => Status::Inactive,
                ]);

                Log::error('Whatsapp WApi status request failed', ['connection' => $connection, 'response' => $statusJson, 'status code' => $status->status()]);
                throw new ConnectionException($statusJson['message'] ?? 'Failed to check Whatsapp WApi connection status', $status->status());
            }
        } catch (\Throwable $th) {
            $connection->update([
                'status' => Status::Inactive,
            ]);

            Log::error('An error occurred while checking Whatsapp WApi connection status', ['connection' => $connection, 'error' => $th]);
            throw new ConnectionException('An error occurred while checking Whatsapp WApi connection status', 500);
        }
    }
}
