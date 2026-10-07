<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Message\MessageType;
use App\Models\Connection;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Message\Apiway\ButtonReply;
use App\Services\Webhook\Handlers\Chat\WhatsappApiwayHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function buttonReplyEvent(array $message): array
{
    return [
        'type' => 'Message',
        'event' => [
            'Info' => [
                'ID' => 'TAP-1',
                'Chat' => '270514354389042@lid',
                'Sender' => '270514354389042@lid',
                'SenderAlt' => '5511987654321:12@s.whatsapp.net',
                'PushName' => 'Anderson',
                'IsFromMe' => false,
                'IsGroup' => false,
                'Timestamp' => '2026-10-07T10:00:00-03:00',
            ],
            'Message' => $message,
        ],
    ];
}

test('a tapped button arrives as the customer saying its label, with the id kept', function () {
    Event::fake();

    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $connection = Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappApiway,
        'name' => 'WhatsApp',
        'status' => ConnectionStatus::Active,
        'credentials' => ['instance_id' => 'INST-1', 'token' => 'test-token'],
    ]);

    (new WhatsappApiwayHandler)->handle($connection, buttonReplyEvent([
        // The shape the core really delivers (production, 2026-10-07): the id
        // at the top, the label inside the oneof wrapper.
        'buttonsResponseMessage' => [
            'Response' => ['SelectedDisplayText' => 'Quero reativar'],
            'contextInfo' => ['stanzaID' => '3EB0D86F13A230E24CF1E5'],
            'selectedButtonID' => 'reativar',
            'type' => 1,
        ],
        'messageContextInfo' => ['deviceListMetadataVersion' => 2],
    ]));

    $message = Message::sole();

    expect($message->message_type)->toBe(MessageType::Text)
        ->and($message->body)->toBe('Quero reativar')
        ->and(ButtonReply::from($message->meta['Message'])['id'])->toBe('reativar');
});

test('every spelling WhatsApp has for a tap reads the same', function (array $message, ?string $id, ?string $title) {
    expect(ButtonReply::from($message))->toBe(['id' => $id, 'title' => $title]);
})->with([
    'buttons, older casing' => [['buttonsResponseMessage' => ['selectedButtonId' => 'a', 'selectedDisplayText' => 'Sim']], 'a', 'Sim'],
    'template button' => [['templateButtonReplyMessage' => ['selectedID' => 'b', 'selectedDisplayText' => 'Não']], 'b', 'Não'],
    'list row' => [['listResponseMessage' => ['title' => 'Pro', 'singleSelectReply' => ['selectedRowID' => 'row_b']]], 'row_b', 'Pro'],
    'native flow' => [['interactiveResponseMessage' => [
        'body' => ['text' => 'Talvez'],
        'nativeFlowResponseMessage' => ['name' => 'quick_reply', 'paramsJSON' => '{"id":"c"}'],
    ]], 'c', 'Talvez'],
]);

test('an ordinary message is not a tap', function () {
    expect(ButtonReply::from(['conversation' => 'oi']))->toBeNull()
        ->and(ButtonReply::from(null))->toBeNull();
});
