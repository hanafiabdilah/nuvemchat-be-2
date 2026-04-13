<?php

namespace App\Services\Connection;

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status;
use App\Exceptions\ConnectionException;
use App\Models\Connection;
use App\Services\Connection\ChannelFactory;
use Illuminate\Support\Facades\Log;

class ConnectionService
{
    private function uniqueApiKey()
    {
        $key = bin2hex(random_bytes(32));

        if (Connection::where('api_key', $key)->exists()) return $this->uniqueApiKey();

        return $key;
    }

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

    public function checkStatus(Connection $connection): void
    {
        $channel = ChannelFactory::make($connection->channel);
        $channel->checkStatus($connection);
    }

    public function generateApiKey(Connection $connection): string
    {
        $key = $this->uniqueApiKey();

        $connection->update([
            'api_key' => $key,
        ]);

        return $key;
    }

    public function disconnect(Connection $connection): void
    {
        $channel = ChannelFactory::make($connection->channel);
        $channel->disconnect($connection);
    }

    public function delete(Connection $connection): void
    {
        // Validation: Instagram and WhatsApp Official connections must be disconnected first
        if (in_array($connection->channel, [Channel::Instagram, Channel::WhatsappOfficial]) && $connection->status === Status::Active) {
            $channelName = $connection->channel === Channel::Instagram ? 'Instagram' : 'WhatsApp';
            $settingsPath = $connection->channel === Channel::Instagram 
                ? 'Instagram Settings → Security → Apps and Websites'
                : 'Facebook Business Integrations page';
            
            throw new ConnectionException(
                "Cannot delete an active {$channelName} connection. Please disconnect from {$channelName} first by visiting your {$settingsPath}, then try again.",
                400
            );
        }

        // For Instagram/WhatsApp with Inactive status, disconnect might have failed
        // but we allow deletion since credentials should already be cleared
        if (in_array($connection->channel, [Channel::Instagram, Channel::WhatsappOfficial]) && $connection->status === Status::Inactive) {
            // Check if credentials still exist
            if (!empty($connection->credentials)) {
                $channelName = $connection->channel === Channel::Instagram ? 'Instagram' : 'WhatsApp';
                throw new ConnectionException(
                    "{$channelName} connection still has active credentials. Please ensure the connection is fully disconnected first.",
                    400
                );
            }
        }

        // For other channels, try to disconnect first
        if (!in_array($connection->channel, [Channel::Instagram, Channel::WhatsappOfficial])) {
            try {
                $this->disconnect($connection);
            } catch (\Throwable $th) {
                // Log the error but continue with deletion for other channels
                \Illuminate\Support\Facades\Log::warning('Failed to disconnect before deleting connection', [
                    'connection_id' => $connection->id,
                    'error' => $th->getMessage(),
                ]);
            }
        }

        // Delete the connection
        $connection->delete();
    }
}
