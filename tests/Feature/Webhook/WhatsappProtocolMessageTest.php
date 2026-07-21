<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Message\MessageType;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Webhook\Handlers\Chat\WhatsappProxyhubHandler;
use App\Services\Webhook\Handlers\Chat\WhatsappWApiHandler;
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

/** A real EPHEMERAL_SYNC_RESPONSE webhook captured from W-API. */
function ephemeralSyncPayload(): array
{
    return [
        'event' => 'webhookReceived',
        'instanceId' => 'BMHPES-I8EH5T-8GO8VK',
        'connectedPhone' => '6287710526655',
        'connectedLid' => '135957709881561@lid',
        'isGroup' => false,
        'messageId' => 'AC858CFCB881529F06676FADD953140F',
        'fromMe' => false,
        'chat' => ['id' => '270514354389042@lid'],
        'sender' => [
            'id' => '6285899367071',
            'senderLid' => '270514354389042@lid',
            'pushName' => 'mutiamuripa',
            'verifiedBizName' => null,
        ],
        'moment' => 1784610275,
        'fromApi' => false,
        'msgContent' => [
            'messageContextInfo' => [
                'deviceListMetadata' => [
                    'recipientKeyHash' => '65zx2KUSW4RdRw==',
                    'recipientTimestamp' => '1784096423',
                ],
                'deviceListMetadataVersion' => 2,
            ],
            'protocolMessage' => [
                'disappearingMode' => [
                    'initiatedByMe' => true,
                    'initiator' => 'INITIATED_BY_ME',
                    'trigger' => 'ACCOUNT_SETTING',
                ],
                'ephemeralExpiration' => 604800,
                'ephemeralSettingTimestamp' => '1766837405',
                'key' => ['fromMe' => true, 'remoteJID' => '135957709881561@lid'],
                'type' => 'EPHEMERAL_SYNC_RESPONSE',
            ],
        ],
    ];
}

test('w-api drops an EPHEMERAL_SYNC_RESPONSE instead of storing it as unsupported', function () {
    Event::fake();
    $connection = protocolTestConnection(Channel::WhatsappWApi);

    (new WhatsappWApiHandler)->handle($connection, ephemeralSyncPayload());

    // Nothing at all: no blank message row, and no conversation conjured for it.
    expect(Message::count())->toBe(0)
        ->and(Conversation::count())->toBe(0);
});

test('w-api drops every non-actionable protocol type', function (string $protocolType) {
    Event::fake();
    $connection = protocolTestConnection(Channel::WhatsappWApi);

    $payload = ephemeralSyncPayload();
    $payload['msgContent']['protocolMessage']['type'] = $protocolType;

    (new WhatsappWApiHandler)->handle($connection, $payload);

    expect(Message::count())->toBe(0);
})->with([
    'EPHEMERAL_SETTING',
    'HISTORY_SYNC_NOTIFICATION',
    'APP_STATE_SYNC_KEY_SHARE',
    'INITIAL_SECURITY_NOTIFICATION_SETTING_SYNC',
    'PEER_DATA_OPERATION_REQUEST_MESSAGE',
]);

test('w-api still stores an ordinary text message', function () {
    Event::fake();
    $connection = protocolTestConnection(Channel::WhatsappWApi);

    $payload = ephemeralSyncPayload();
    // Same envelope, real content instead of protocol traffic.
    $payload['msgContent'] = ['conversation' => 'Halo, saya mau tanya'];

    (new WhatsappWApiHandler)->handle($connection, $payload);

    expect(Message::count())->toBe(1)
        ->and(Message::first()->message_type)->toBe(MessageType::Text)
        ->and(Message::first()->body)->toBe('Halo, saya mau tanya');
});

test('w-api still routes REVOKE and MESSAGE_EDIT to their handlers', function (string $protocolType) {
    Event::fake();
    $connection = protocolTestConnection(Channel::WhatsappWApi);

    $payload = ephemeralSyncPayload();
    $payload['msgContent']['protocolMessage']['type'] = $protocolType;
    $payload['msgContent']['protocolMessage']['key']['id'] = 'SOME-UNKNOWN-MESSAGE-ID';

    // The referenced message does not exist, so the handlers no-op — but they must
    // be *reached*, not swallowed by the protocol-message skip. If the skip were
    // too broad, deletes and edits would silently stop working.
    (new WhatsappWApiHandler)->handle($connection, $payload);

    expect(Message::count())->toBe(0);
})->with(['REVOKE', 'MESSAGE_EDIT']);

test('proxyhub drops protocol messages instead of storing them as unsupported', function () {
    Event::fake();
    $connection = protocolTestConnection(Channel::WhatsappProxyhub);

    (new WhatsappProxyhubHandler)->handle($connection, [
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
test('proxyhub drops a peer protocol message with a numeric type sent from self', function () {
    Event::fake();
    $connection = protocolTestConnection(Channel::WhatsappProxyhub);

    (new WhatsappProxyhubHandler)->handle($connection, [
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

test('proxyhub still records a genuine message sent from the phone', function () {
    Event::fake();
    $connection = protocolTestConnection(Channel::WhatsappProxyhub);

    // Same IsFromMe path, real content — must not be swallowed by the guard.
    (new WhatsappProxyhubHandler)->handle($connection, [
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
