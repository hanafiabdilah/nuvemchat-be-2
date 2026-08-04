<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\SenderType;
use App\Jobs\ProcessCoexistenceWebhook;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Webhook\Handlers\Chat\WhatsappCoexistenceHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

const COEX_WABA_ID = '111222333444555';
const COEX_BUSINESS_PHONE = '5511999990000';
const COEX_CUSTOMER_PHONE = '5511888887777';

function coexConnection(): Connection
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);

    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappOfficial,
        'name' => 'WhatsApp Coex',
        'status' => ConnectionStatus::Active,
        'credentials' => [
            'phone_number_id' => '106540352242922',
            'access_token' => 'test-token',
            'business_account_id' => COEX_WABA_ID,
            'display_phone_number' => COEX_BUSINESS_PHONE,
            'is_coexistence' => true,
        ],
    ]);
}

function coexEchoValue(string $messageId = 'wamid.echo1'): array
{
    return [
        'messaging_product' => 'whatsapp',
        'metadata' => [
            'display_phone_number' => COEX_BUSINESS_PHONE,
            'phone_number_id' => '106540352242922',
        ],
        'message_echoes' => [[
            'from' => COEX_BUSINESS_PHONE,
            'to' => COEX_CUSTOMER_PHONE,
            'id' => $messageId,
            'timestamp' => '1754300000',
            'type' => 'text',
            'text' => ['body' => 'Respondido pelo celular'],
        ]],
    ];
}

test('an smb_message_echo becomes an Outgoing message and never starts a flow', function () {
    Event::fake();
    $connection = coexConnection();

    (new WhatsappCoexistenceHandler)->handleChange($connection, [
        'field' => 'smb_message_echoes',
        'value' => coexEchoValue(),
    ]);

    $message = Message::where('external_id', 'wamid.echo1')->first();
    expect($message)->not->toBeNull()
        ->and($message->sender_type)->toBe(SenderType::Outgoing)
        ->and($message->body)->toBe('Respondido pelo celular');

    $conversation = $message->conversation;
    expect($conversation->connection_id)->toBe($connection->id)
        ->and($conversation->contact->external_id)->toBe(COEX_CUSTOMER_PHONE);

    // Duplicate delivery of the same echo must not create a second row.
    (new WhatsappCoexistenceHandler)->handleChange($connection, [
        'field' => 'smb_message_echoes',
        'value' => coexEchoValue(),
    ]);

    expect(Message::where('external_id', 'wamid.echo1')->count())->toBe(1);
});

test('a history chunk imports both directions quietly into one conversation', function () {
    Event::fake();
    $connection = coexConnection();

    (new WhatsappCoexistenceHandler)->ingestHistoryChunk($connection, [
        'messaging_product' => 'whatsapp',
        'metadata' => ['display_phone_number' => COEX_BUSINESS_PHONE],
        'history' => [[
            'metadata' => ['phase' => 0, 'chunk_order' => 1, 'progress' => 100],
            'threads' => [[
                'id' => COEX_CUSTOMER_PHONE,
                'messages' => [
                    [
                        'from' => COEX_BUSINESS_PHONE,
                        'to' => COEX_CUSTOMER_PHONE,
                        'id' => 'wamid.hist2',
                        'timestamp' => '1754300100',
                        'type' => 'text',
                        'text' => ['body' => 'Olá!'],
                        'history_context' => ['status' => 'READ'],
                    ],
                    [
                        'from' => COEX_CUSTOMER_PHONE,
                        'to' => COEX_BUSINESS_PHONE,
                        'id' => 'wamid.hist1',
                        'timestamp' => '1754300000',
                        'type' => 'text',
                        'text' => ['body' => 'Oi'],
                        'history_context' => ['status' => 'DELIVERED'],
                    ],
                ],
            ]],
        ]],
    ]);

    $conversation = Conversation::where('connection_id', $connection->id)->first();
    expect($conversation)->not->toBeNull()
        ->and($conversation->status)->toBe(ConversationStatus::Pending)
        ->and($conversation->messages()->count())->toBe(2);

    $incoming = Message::where('external_id', 'wamid.hist1')->first();
    $outgoing = Message::where('external_id', 'wamid.hist2')->first();
    expect($incoming->sender_type)->toBe(SenderType::Incoming)
        // Imported history arrives read — no unread storm.
        ->and($incoming->read_at)->not->toBeNull()
        ->and($outgoing->sender_type)->toBe(SenderType::Outgoing);

    $state = $connection->fresh()->credentials['smb_data_sync']['history'];
    expect($state['status'])->toBe('done')
        ->and($state['messages'])->toBe(2);
});

test('a declined history chunk records the declined state instead of importing', function () {
    Event::fake();
    $connection = coexConnection();

    (new WhatsappCoexistenceHandler)->ingestHistoryChunk($connection, [
        'messaging_product' => 'whatsapp',
        'metadata' => ['display_phone_number' => COEX_BUSINESS_PHONE],
        'history' => [[
            'errors' => [[
                'code' => 2593109,
                'title' => 'History sync is turned off by the business from the WhatsApp Business App',
            ]],
        ]],
    ]);

    expect(Conversation::count())->toBe(0)
        ->and($connection->fresh()->credentials['smb_data_sync']['history']['status'])->toBe('declined');
});

test('smb_app_state_sync upserts contacts and ignores removals', function () {
    Event::fake();
    $connection = coexConnection();

    (new WhatsappCoexistenceHandler)->ingestStateSync($connection, [
        'messaging_product' => 'whatsapp',
        'state_sync' => [
            [
                'type' => 'contact',
                'action' => 'add',
                'contact' => ['full_name' => 'Maria Silva', 'first_name' => 'Maria', 'phone_number' => '+55 11 88888-7777'],
            ],
            [
                'type' => 'contact',
                'action' => 'remove',
                'contact' => ['full_name' => 'Removido', 'phone_number' => '+55 11 77777-6666'],
            ],
        ],
    ]);

    $contact = Contact::where('external_id', COEX_CUSTOMER_PHONE)->first();
    expect($contact)->not->toBeNull()
        ->and($contact->name)->toBe('Maria Silva')
        ->and(Contact::where('external_id', '5511777776666')->exists())->toBeFalse()
        ->and($connection->fresh()->credentials['smb_data_sync']['contacts_synced'])->toBe(1);
});

test('account_update PARTNER_REMOVED deactivates the connection', function () {
    Event::fake();
    $connection = coexConnection();

    (new WhatsappCoexistenceHandler)->handleChange($connection, [
        'field' => 'account_update',
        'value' => ['event' => 'PARTNER_REMOVED'],
    ]);

    expect($connection->fresh()->status)->toBe(ConnectionStatus::Inactive);
});

test('the webhook route queues heavy coexistence fields and still handles live messages', function () {
    Event::fake();
    Queue::fake();
    $connection = coexConnection();

    $response = $this->postJson('/webhook/whatsapp', [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => COEX_WABA_ID,
            'changes' => [
                [
                    'field' => 'history',
                    'value' => ['history' => []],
                ],
                [
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => COEX_BUSINESS_PHONE, 'phone_number_id' => '106540352242922'],
                        'contacts' => [['wa_id' => COEX_CUSTOMER_PHONE, 'profile' => ['name' => 'Maria']]],
                        'messages' => [[
                            'from' => COEX_CUSTOMER_PHONE,
                            'id' => 'wamid.live1',
                            'timestamp' => '1754300200',
                            'type' => 'text',
                            'text' => ['body' => 'Mensagem ao vivo'],
                        ]],
                    ],
                ],
            ],
        ]],
    ]);

    $response->assertOk();

    Queue::assertPushed(ProcessCoexistenceWebhook::class, fn ($job) => $job->field === 'history');

    $live = Message::where('external_id', 'wamid.live1')->first();
    expect($live)->not->toBeNull()
        ->and($live->sender_type)->toBe(SenderType::Incoming);
});
