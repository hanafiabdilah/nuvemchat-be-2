<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Webhook\Handlers\Chat\WhatsappOfficialHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function unsupportedTestConnection(): Connection
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);

    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappOfficial,
        'name' => 'WhatsApp',
        'status' => ConnectionStatus::Active,
        'credentials' => ['access_token' => 'test-token', 'phone_number_id' => '1083508778182246'],
    ]);
}

/**
 * Real Cloud API payload: error 131051 "Message type unknown". Meta relays
 * these when the content type can't be delivered — nothing usable to store.
 */
test('whatsapp official drops messages with type unsupported', function () {
    Event::fake();
    $connection = unsupportedTestConnection();

    (new WhatsappOfficialHandler)->handle($connection, [
        'id' => '1463349248861966',
        'changes' => [[
            'value' => [
                'messaging_product' => 'whatsapp',
                'metadata' => [
                    'display_phone_number' => '555481061675',
                    'phone_number_id' => '1083508778182246',
                ],
                'contacts' => [[
                    'wa_id' => '12134098546',
                    'user_id' => 'US.881116011379103',
                ]],
                'messages' => [[
                    'from' => '12134098546',
                    'from_user_id' => 'US.881116011379103',
                    'id' => 'wamid.HBgLMTIxMzQwOTg1NDYVAgASGBI5RDE1NDZFQkNFQzU4RUMxOTcA',
                    'timestamp' => '1785943076',
                    'errors' => [[
                        'code' => 131051,
                        'title' => 'Message type unknown',
                        'message' => 'Message type unknown',
                        'error_data' => ['details' => 'Message type is currently not supported.'],
                    ]],
                    'type' => 'unsupported',
                    'unsupported' => ['type' => 'unknown'],
                ]],
            ],
            'field' => 'messages',
        ]],
    ]);

    expect(Message::count())->toBe(0)
        ->and(Conversation::count())->toBe(0);
});

test('whatsapp official still records a genuine text message', function () {
    Event::fake();
    $connection = unsupportedTestConnection();

    (new WhatsappOfficialHandler)->handle($connection, [
        'id' => '1463349248861966',
        'changes' => [[
            'value' => [
                'messaging_product' => 'whatsapp',
                'metadata' => [
                    'display_phone_number' => '555481061675',
                    'phone_number_id' => '1083508778182246',
                ],
                'contacts' => [[
                    'profile' => ['name' => 'Cliente'],
                    'wa_id' => '12134098546',
                ]],
                'messages' => [[
                    'from' => '12134098546',
                    'id' => 'wamid.REAL-TEXT-1',
                    'timestamp' => '1785943076',
                    'type' => 'text',
                    'text' => ['body' => 'hello'],
                ]],
            ],
            'field' => 'messages',
        ]],
    ]);

    expect(Message::count())->toBe(1)
        ->and(Message::first()->body)->toBe('hello');
});
