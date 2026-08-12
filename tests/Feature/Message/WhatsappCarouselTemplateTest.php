<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\MessageType;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Connection\Meta\FacebookConfig;
use App\Services\Connection\WhatsApp\WhatsappTemplateService;
use App\Services\Message\MessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function carouselTemplateConnection(): Connection
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappOfficial,
        'name' => 'WA',
        'color' => '#22c55e',
        'status' => ConnectionStatus::Active,
        'credentials' => [
            'phone_number_id' => '111000111',
            'access_token' => 'wa-token',
            'business_account_id' => '222000222',
        ],
    ]);
}

test('uploading a card sample opens a session then pushes the bytes for a handle', function () {
    Setting::set(FacebookConfig::KEY_APP_ID, '900900900');

    Http::fake([
        'graph.facebook.com/v25.0/900900900/uploads' => Http::response(['id' => 'upload:SESSION123']),
        'graph.facebook.com/v25.0/upload:SESSION123' => Http::response(['h' => '4::aW1hZ2U=']),
    ]);

    $handle = (new WhatsappTemplateService())->uploadHandle(
        carouselTemplateConnection(),
        'binary-bytes',
        'image/jpeg',
        'card.jpg'
    );

    expect($handle)->toBe('4::aW1hZ2U=');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/900900900/uploads')
        && $request->data()['file_length'] === strlen('binary-bytes')
        && $request->data()['file_type'] === 'image/jpeg');

    // The upload leg is particular: OAuth, not Bearer, and an explicit offset.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/upload:SESSION123')
        && $request->header('Authorization')[0] === 'OAuth wa-token'
        && $request->header('file_offset')[0] === '0'
        && $request->body() === 'binary-bytes');
});

test('uploading without a configured app id fails before calling Meta', function () {
    Setting::set(FacebookConfig::KEY_APP_ID, null);
    Http::fake();

    expect(fn () => (new WhatsappTemplateService())->uploadHandle(
        carouselTemplateConnection(),
        'bytes',
        'image/jpeg',
        'card.jpg'
    ))->toThrow(RuntimeException::class, 'Facebook App ID is not configured');

    Http::assertNothingSent();
});

test('a carousel template is sent with its card components intact', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.TPL1']]])]);

    $connection = carouselTemplateConnection();

    $contact = Contact::create([
        'connection_id' => $connection->id,
        'external_id' => '5511999999999',
        'name' => 'Ana',
        'username' => '5511999999999',
    ]);

    $conversation = Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => '5511999999999',
        'status' => ConversationStatus::Active,
    ]);

    $components = [
        ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Ana']]],
        [
            'type' => 'carousel',
            'cards' => [
                [
                    'card_index' => 0,
                    'components' => [
                        ['type' => 'header', 'parameters' => [['type' => 'image', 'image' => ['link' => 'https://cdn.example.com/1.jpg']]]],
                        ['type' => 'button', 'sub_type' => 'url', 'index' => 0, 'parameters' => [['type' => 'text', 'text' => 'ofertas']]],
                    ],
                ],
                [
                    'card_index' => 1,
                    'components' => [
                        ['type' => 'header', 'parameters' => [['type' => 'image', 'image' => ['link' => 'https://cdn.example.com/2.jpg']]]],
                        ['type' => 'button', 'sub_type' => 'url', 'index' => 0, 'parameters' => [['type' => 'text', 'text' => 'headphones']]],
                    ],
                ],
            ],
        ],
    ];

    $message = (new MessageService())->sendTemplate($conversation, [
        'template_name' => 'ofertas_4_4',
        'language' => 'pt_BR',
        'components' => $components,
    ]);

    Http::assertSent(function ($request) {
        $body = $request->data();
        $carousel = collect($body['template']['components'])->firstWhere('type', 'carousel');

        return ($body['type'] ?? null) === 'template'
            && $body['template']['name'] === 'ofertas_4_4'
            && count($carousel['cards']) === 2
            && $carousel['cards'][1]['card_index'] === 1
            && $carousel['cards'][1]['components'][0]['parameters'][0]['image']['link'] === 'https://cdn.example.com/2.jpg';
    });

    expect($message)->toBeInstanceOf(Message::class)
        ->and($message->message_type)->toBe(MessageType::Template)
        // The sent template is kept on the message, so the thread can show it.
        ->and($message->meta['template']['components'])->toBe($components);
});
