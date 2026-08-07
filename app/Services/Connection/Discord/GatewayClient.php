<?php

namespace App\Services\Connection\Discord;

use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

/**
 * Minimal Discord Gateway (v10) client for ONE bot token, built on Pawl —
 * Discord delivers chat messages only over this WebSocket, never via webhook.
 *
 * Deliberately simple: on any disconnect it re-identifies from scratch with
 * exponential backoff instead of resuming the session. A reconnect gap can
 * drop DMs sent during it — acceptable for now, and far simpler than
 * implementing RESUME. Intents: DIRECT_MESSAGES only (DM content is exempt
 * from the privileged MESSAGE_CONTENT intent).
 */
class GatewayClient
{
    private const GATEWAY_URL = 'wss://gateway.discord.gg/?v=10&encoding=json';
    private const INTENT_DIRECT_MESSAGES = 1 << 12; // 4096

    private const OP_DISPATCH = 0;
    private const OP_HEARTBEAT = 1;
    private const OP_IDENTIFY = 2;
    private const OP_RECONNECT = 7;
    private const OP_INVALID_SESSION = 9;
    private const OP_HELLO = 10;
    private const OP_HEARTBEAT_ACK = 11;

    private ?WebSocket $ws = null;
    private ?TimerInterface $heartbeatTimer = null;
    private bool $heartbeatAcked = true;
    private ?int $lastSequence = null;
    private int $reconnectDelay = 5;
    private bool $stopped = false;

    /**
     * @param \Closure $onDispatch  fn(string $eventType, array $data): void
     * @param \Closure $onLog       fn(string $level, string $message, array $context = []): void
     */
    public function __construct(
        private LoopInterface $loop,
        private string $token,
        private \Closure $onDispatch,
        private \Closure $onLog,
    ) {
    }

    public function start(): void
    {
        $this->stopped = false;
        $this->connect();
    }

    public function stop(): void
    {
        $this->stopped = true;
        $this->cancelHeartbeat();

        if ($this->ws) {
            $this->ws->close();
            $this->ws = null;
        }
    }

    private function connect(): void
    {
        if ($this->stopped) {
            return;
        }

        $connector = new Connector($this->loop);

        $connector(self::GATEWAY_URL)->then(
            function (WebSocket $ws) {
                $this->ws = $ws;

                $ws->on('message', function (MessageInterface $message) {
                    $this->handlePayload((string) $message);
                });

                $ws->on('close', function ($code = null, $reason = null) {
                    ($this->onLog)('warning', 'Gateway closed', ['code' => $code, 'reason' => (string) $reason]);
                    $this->scheduleReconnect();
                });
            },
            function (\Throwable $e) {
                ($this->onLog)('error', 'Gateway connection failed', ['error' => $e->getMessage()]);
                $this->scheduleReconnect();
            }
        );
    }

    private function handlePayload(string $raw): void
    {
        $payload = json_decode($raw, true);

        if (!is_array($payload)) {
            return;
        }

        $op = $payload['op'] ?? null;

        switch ($op) {
            case self::OP_HELLO:
                $this->startHeartbeat((int) ($payload['d']['heartbeat_interval'] ?? 41250));
                $this->identify();
                break;

            case self::OP_HEARTBEAT:
                $this->sendHeartbeat();
                break;

            case self::OP_HEARTBEAT_ACK:
                $this->heartbeatAcked = true;
                break;

            case self::OP_RECONNECT:
            case self::OP_INVALID_SESSION:
                ($this->onLog)('info', 'Gateway asked for a reconnect', ['op' => $op]);
                $this->ws?->close();
                break;

            case self::OP_DISPATCH:
                if (isset($payload['s'])) {
                    $this->lastSequence = (int) $payload['s'];
                }

                $eventType = $payload['t'] ?? null;

                if ($eventType === 'READY') {
                    $this->reconnectDelay = 5; // healthy session → reset backoff
                    ($this->onLog)('info', 'Gateway ready', [
                        'bot' => $payload['d']['user']['username'] ?? null,
                    ]);
                } elseif (in_array($eventType, ['MESSAGE_CREATE', 'MESSAGE_UPDATE', 'MESSAGE_DELETE'], true)) {
                    ($this->onDispatch)($eventType, $payload['d'] ?? []);
                }
                break;
        }
    }

    private function identify(): void
    {
        $this->send([
            'op' => self::OP_IDENTIFY,
            'd' => [
                'token' => $this->token,
                'intents' => self::INTENT_DIRECT_MESSAGES,
                'properties' => [
                    'os' => PHP_OS_FAMILY,
                    'browser' => 'nuvemchat',
                    'device' => 'nuvemchat',
                ],
            ],
        ]);
    }

    private function startHeartbeat(int $intervalMs): void
    {
        $this->cancelHeartbeat();
        $this->heartbeatAcked = true;

        $this->heartbeatTimer = $this->loop->addPeriodicTimer($intervalMs / 1000, function () {
            // No ACK since the last beat → zombie connection; close and let the
            // close handler reconnect.
            if (!$this->heartbeatAcked) {
                ($this->onLog)('warning', 'Heartbeat ACK missed, reconnecting');
                $this->ws?->close();
                return;
            }

            $this->sendHeartbeat();
        });

        $this->sendHeartbeat();
    }

    private function sendHeartbeat(): void
    {
        $this->heartbeatAcked = false;
        $this->send(['op' => self::OP_HEARTBEAT, 'd' => $this->lastSequence]);
    }

    private function cancelHeartbeat(): void
    {
        if ($this->heartbeatTimer) {
            $this->loop->cancelTimer($this->heartbeatTimer);
            $this->heartbeatTimer = null;
        }
    }

    private function scheduleReconnect(): void
    {
        $this->cancelHeartbeat();
        $this->ws = null;

        if ($this->stopped) {
            return;
        }

        $delay = $this->reconnectDelay;
        $this->reconnectDelay = min($this->reconnectDelay * 2, 60);

        ($this->onLog)('info', "Reconnecting in {$delay}s");
        $this->loop->addTimer($delay, fn () => $this->connect());
    }

    private function send(array $payload): void
    {
        $this->ws?->send(json_encode($payload));
    }
}
