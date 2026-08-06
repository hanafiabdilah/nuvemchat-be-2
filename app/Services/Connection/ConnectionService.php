<?php

namespace App\Services\Connection;

use App\Enums\Connection\Channel;
use App\Models\Connection;
use App\Services\Connection\ChannelFactory;

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
        // Best-effort disconnect first so the remote side (webhooks, tokens,
        // phone registration) is cleaned up when possible.
        try {
            $this->disconnect($connection);
        } catch (\Throwable $th) {
            \Illuminate\Support\Facades\Log::warning('Failed to disconnect before deleting connection', [
                'connection_id' => $connection->id,
                'error' => $th->getMessage(),
            ]);
        }

        // API Way instances are purchased assets: deleting the connection only
        // returns the instance to the tenant's pool. Cancelling the ProxyBR
        // subscription is a separate, explicit action on the Instances page.
        if ($connection->channel === Channel::WhatsappApiway) {
            (new \App\Services\Connection\Channels\WhatsappApiwayChannel())->releaseInstance($connection);
        }

        // Delete the connection
        $connection->delete();
    }
}
