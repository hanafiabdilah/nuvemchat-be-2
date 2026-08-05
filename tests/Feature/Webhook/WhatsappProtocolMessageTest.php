<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Webhook\Handlers\Chat\WhatsappApiwayHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function protocolTestConnection(Channel $channel): Connection
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);

    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => $channel,
        'name' => 'WhatsApp',
        'status' => ConnectionStatus::Active,
        'credentials' => ['instance_id' => 'BMHPES-I8EH5T-8GO8VK', 'token' => 'test-token'],
    ]);
}

test('api way drops protocol messages instead of storing them as unsupported', function () {
    Event::fake();
    $connection = protocolTestConnection(Channel::WhatsappApiway);

    (new WhatsappApiwayHandler)->handle($connection, [
        'type' => 'Message',
        'event' => [
            'Info' => [
                'ID' => 'AC858CFCB881529F06676FADD953140F',
                'Chat' => '270514354389042@lid',
                'Sender' => '270514354389042@lid',
                'SenderAlt' => '6285899367071:12@s.whatsapp.net',
                'PushName' => 'mutiamuripa',
                'IsFromMe' => false,
                'IsGroup' => false,
                'Timestamp' => '2026-07-21T10:00:00Z',
            ],
            'Message' => [
                'messageContextInfo' => ['deviceListMetadataVersion' => 2],
                'protocolMessage' => ['type' => 'EPHEMERAL_SYNC_RESPONSE'],
            ],
        ],
    ]);

    expect(Message::count())->toBe(0)
        ->and(Conversation::count())->toBe(0);
});

/**
 * Real ProxyBR payload: INITIAL_SECURITY_NOTIFICATION_SETTING_SYNC.
 *
 * Two traits the earlier cases did not cover:
 *  - `protocolMessage.type` is the **numeric** proto enum (9), not a string;
 *  - `IsFromMe` is true, so it takes the handleOwnMessage path — the guard has
 *    to sit before that branch or it would be stored as an outgoing message.
 */
test('api way drops a peer protocol message with a numeric type sent from self', function () {
    Event::fake();
    $connection = protocolTestConnection(Channel::WhatsappApiway);

    (new WhatsappApiwayHandler)->handle($connection, [
        'type' => 'Message',
        'event' => [
            'Info' => [
                'Category' => 'peer',
                'Chat' => '6282122787699@s.whatsapp.net',
                'ID' => '3A42CDA0A7298B45F149',
                'IsFromMe' => true,
                'IsGroup' => false,
                'PushName' => null,
                'Sender' => '6282122787699@s.whatsapp.net',
                'SenderAlt' => '204457707106524@lid',
                'Timestamp' => '2026-07-21T10:05:52-03:00',
                'Type' => 'text',
            ],
            'Message' => [
                'messageContextInfo' => [
                    'deviceListMetadata' => [
                        'senderKeyHash' => 'f9R9c5OtQG2Wiw==',
                        'senderTimestamp' => 1784639143,
                    ],
                    'deviceListMetadataVersion' => 2,
                ],
                'protocolMessage' => [
                    'initialSecurityNotificationSettingSync' => ['securityNotificationEnabled' => false],
                    'type' => 9,
                ],
            ],
            'RawMessage' => [
                'protocolMessage' => [
                    'initialSecurityNotificationSettingSync' => ['securityNotificationEnabled' => false],
                    'type' => 9,
                ],
            ],
        ],
    ]);

    expect(Message::count())->toBe(0)
        ->and(Conversation::count())->toBe(0);
});

test('api way still records a genuine message sent from the phone', function () {
    Event::fake();
    $connection = protocolTestConnection(Channel::WhatsappApiway);

    // Same IsFromMe path, real content — must not be swallowed by the guard.
    (new WhatsappApiwayHandler)->handle($connection, [
        'type' => 'Message',
        'event' => [
            'Info' => [
                'Chat' => '6282122787699@s.whatsapp.net',
                'ID' => 'REAL-OUTGOING-1',
                'IsFromMe' => true,
                'IsGroup' => false,
                'Sender' => '6282122787699@s.whatsapp.net',
                'SenderAlt' => '204457707106524@lid',
                'Timestamp' => '2026-07-21T10:05:52-03:00',
            ],
            'Message' => ['conversation' => 'from desktop'],
        ],
    ]);

    expect(Message::count())->toBe(1)
        ->and(Message::first()->body)->toBe('from desktop');
});
