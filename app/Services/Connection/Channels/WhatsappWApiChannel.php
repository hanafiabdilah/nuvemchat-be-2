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

    private function checkInstanceStatus(Connection $connection): Connection
    {
        $status = Http::withHeaders([
            'Authorization' => 'Bearer ' . $connection->credentials['token'],
        ])->get('https://api.w-api.app/v1/instance/status-instance?instanceId=' . $connection->credentials['instance_id']);
        $statusJson = $status->json();

        if($status->failed()){
            Log::error('Whatsapp WApi status request failed', ['connection' => $connection, 'response' => $statusJson, 'status code' => $status->status()]);
            throw new ConnectionException($statusJson['message'] ?? 'Failed to connect to Whatsapp WApi', $status->status());
        }

        $connection->update([
            'status' => $statusJson['connected'] === true ? Status::Active : Status::Inactive,
        ]);

        return $connection;
    }

    private function handleOwnInstance(Connection $connection, array $data): Connection
    {
        if(Connection::where('id', '!=', $connection->id)->where('channel', Channel::WhatsappWApi)->where('credentials->instance_id', $data['instance_id'])->exists()) {
            throw ValidationException::withMessages(['instance_id' => 'The instance_id has already been taken.']);
        }

        $connection->update([
            'credentials' => [
                'instance_id' => $data['instance_id'],
                'token' => $data['token'],
            ],
        ]);

        $connection = $this->checkInstanceStatus($connection);

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
            $endpoint = 'https://api.w-api.app/v1/webhook/' . $webhookPath . '?instanceId=' . $data['instance_id'];
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

        return $connection;
    }

    private function handleManagedInstance(Connection $connection): Connection
    {
        // Instance created before, just check status and return
        if(isset($connection->credentials['instance_id']) && isset($connection->credentials['token'])){
            return $this->checkInstanceStatus($connection);
        }

        $payload = [
            'instanceName' => config('app.name') . ' - #' . $connection->id,
            'rejectCalls' => true,
            'callMessage' => 'This number does not accept calls.',
            'webhookConnectedUrl' => route('webhook.chat', ['id' => $connection->id]),
            'webhookDisconnectedUrl' => route('webhook.chat', ['id' => $connection->id]),
            'webhookDeliveryUrl' => route('webhook.chat', ['id' => $connection->id]),
            'webhookReceivedUrl' => route('webhook.chat', ['id' => $connection->id]),
            'webhookStatusUrl' => route('webhook.chat', ['id' => $connection->id]),
            'webhookPresenceUrl' => route('webhook.chat', ['id' => $connection->id]),
        ];

        Log::info('Creating Whatsapp WApi managed instance', ['connection' => $connection, 'payload' => $payload]);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.wapi.managed_token'),
        ])->post('https://api.w-api.app/v1/integrator/create-instance', $payload);

        $responseJson = $response->json();

        if($response->failed()){
            Log::error('Whatsapp WApi managed instance creation failed', ['connection' => $connection, 'response' => $responseJson, 'status code' => $response->status()]);
            throw new ConnectionException('Failed to create managed instance on Whatsapp WApi', 500);
        }

        $connection->update([
            'status' => Status::Pending,
            'credentials' => [
                'instance_id' => $responseJson['instanceId'],
                'token' => $responseJson['token'],
                'is_managed' => true,
            ],
        ]);

        return $connection;
    }

    public function connect(Connection $connection, array $data)
    {
        $data['is_managed'] = $connection->credentials['is_managed'] ?? false;

        validator($data, [
            'is_managed' => ['required', 'boolean'],
            'instance_id' => ['required_if:is_managed,false', 'string'],
            'token' => ['required_if:is_managed,false', 'string'],
        ])->validate();

        if($data['is_managed'] || ($connection->credentials['is_managed'] ?? false)){
            $connection = $this->handleManagedInstance($connection);
        }else{
            $connection = $this->handleOwnInstance($connection, $data);
        }

        if($connection->status === Status::Active) return;

        Log::info('Retrieving Whatsapp WApi QR code', ['connection' => $connection]);

        $qr = Http::withHeaders([
            'Authorization' => 'Bearer ' . $connection->credentials['token'],
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

    public function disconnect(Connection $connection): void
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $connection->credentials['token'],
            ])->get('https://api.w-api.app/v1/instance/disconnect?instanceId=' . $connection->credentials['instance_id']);

            $responseJson = $response->json();

            Log::info('Whatsapp WApi disconnect response', ['connection' => $connection, 'response' => $responseJson, 'status code' => $response->status()]);

            if($response->failed()){
                Log::warning('Whatsapp WApi disconnect request failed, but will update status to inactive anyway', [
                    'connection' => $connection,
                    'response' => $responseJson,
                    'status code' => $response->status()
                ]);
            }
        } catch (\Throwable $th) {
            Log::warning('An error occurred while disconnecting from Whatsapp WApi, but will update status to inactive anyway', [
                'connection' => $connection,
                'error' => $th->getMessage()
            ]);
        }

        // Always update status to inactive, even if disconnect API call failed
        // because the user's intent is to disconnect
        $connection->update([
            'status' => Status::Inactive,
            'credentials' => array_merge($connection->credentials, [
                'qr_code' => null,
            ]),
        ]);
    }

    public function checkStatus(Connection $connection): void
    {
        try {
            $status = Http::withHeaders([
                'Authorization' => 'Bearer ' . $connection->credentials['token'],
            ])->get('https://api.w-api.app/v1/instance/status-instance?instanceId=' . $connection->credentials['instance_id']);

            $statusJson = $status->json();

            Log::info('Whatsapp WApi status check response', ['connection' => $connection, 'response' => $statusJson, 'status code' => $status->status()]);

            if($status->failed()){
                $connection->update([
                    'status' => Status::Inactive,
                ]);

                Log::error('Whatsapp WApi status request failed', ['connection' => $connection, 'response' => $statusJson, 'status code' => $status->status()]);
                throw new ConnectionException($statusJson['message'] ?? 'Failed to check Whatsapp WApi connection status', $status->status());
            }

            $connection->update([
                'status' => $statusJson['connected'] === true ? Status::Active : Status::Inactive,
            ]);
        } catch (\Throwable $th) {
            $connection->update([
                'status' => Status::Inactive,
            ]);

            Log::error('An error occurred while checking Whatsapp WApi connection status', ['connection' => $connection, 'error' => $th]);
            throw new ConnectionException('An error occurred while checking Whatsapp WApi connection status', 500);
        }
    }
}
