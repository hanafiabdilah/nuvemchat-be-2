<?php

namespace App\Console\Commands\Discord;

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status;
use App\Models\Connection;
use App\Services\Connection\Discord\GatewayClient;
use App\Services\Webhook\ChatService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;

/**
 * Long-running Gateway listener for every active Discord connection — the
 * Discord counterpart of a webhook receiver (Discord has none for messages).
 * Run under supervisor next to reverb/queue:
 *
 *     php artisan discord:gateway
 *
 * One WebSocket session per connected bot; a periodic reconcile picks up
 * connections created, disconnected or re-tokened while the daemon runs.
 */
class DiscordGatewayCommand extends Command
{
    protected $signature = 'discord:gateway {--reconcile-interval=30 : Seconds between connection reconciliations}';

    protected $description = 'Listen to the Discord Gateway for all active Discord connections';

    /** @var array<int, array{client: GatewayClient, token: string}> */
    private array $clients = [];

    public function handle(ChatService $chatService): int
    {
        $loop = Loop::get();
        $interval = max(5, (int) $this->option('reconcile-interval'));

        $this->reconcile($loop, $chatService);
        $loop->addPeriodicTimer($interval, fn () => $this->reconcile($loop, $chatService));

        $this->info('Discord gateway listener started (Ctrl+C to stop).');
        $loop->run();

        return self::SUCCESS;
    }

    private function reconcile(LoopInterface $loop, ChatService $chatService): void
    {
        try {
            $connections = Connection::where('channel', Channel::Discord)
                ->where('status', Status::Active)
                ->get()
                ->filter(fn (Connection $connection) => !empty($connection->credentials['token']));
        } catch (\Throwable $th) {
            // Long-running process: the DB link can time out between ticks.
            Log::warning('DiscordGateway: reconcile query failed, reconnecting DB', [
                'error' => $th->getMessage(),
            ]);
            DB::reconnect();

            return;
        }

        // Drop sessions for connections that were deleted, disconnected or
        // re-tokened since the last tick.
        foreach ($this->clients as $connectionId => $entry) {
            $current = $connections->firstWhere('id', $connectionId);

            if (!$current || ($current->credentials['token'] ?? null) !== $entry['token']) {
                $entry['client']->stop();
                unset($this->clients[$connectionId]);
                $this->info("Stopped gateway session for connection #{$connectionId}");
            }
        }

        // Open sessions for connections that appeared since the last tick.
        foreach ($connections as $connection) {
            if (isset($this->clients[$connection->id])) {
                continue;
            }

            $connectionId = $connection->id;
            $token = $connection->credentials['token'];

            $client = new GatewayClient(
                $loop,
                $token,
                function (string $eventType, array $data) use ($connectionId, $chatService) {
                    try {
                        // Re-fetch so flow/credential changes made after boot apply.
                        $fresh = Connection::find($connectionId);

                        if (!$fresh || $fresh->status !== Status::Active) {
                            return;
                        }

                        $chatService->handle($fresh, ['t' => $eventType, 'd' => $data]);
                    } catch (\Throwable $th) {
                        Log::error('DiscordGateway: failed to handle dispatch', [
                            'connection_id' => $connectionId,
                            'event' => $eventType,
                            'error' => $th->getMessage(),
                        ]);
                        DB::reconnect();
                    }
                },
                function (string $level, string $message, array $context = []) use ($connectionId) {
                    Log::log($level, "DiscordGateway[#{$connectionId}]: {$message}", $context);
                },
            );

            $client->start();
            $this->clients[$connectionId] = ['client' => $client, 'token' => $token];
            $this->info("Started gateway session for connection #{$connectionId}");
        }
    }
}
