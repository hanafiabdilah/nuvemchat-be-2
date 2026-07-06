<?php

use App\Enums\Connection\Channel;
use App\Models\Connection;
use App\Services\Connection\WhatsApp\WhatsappTemplateService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
});

function templateConnection(): Connection
{
    // Unsaved model — the service only reads the credentials array.
    return new Connection([
        'channel' => Channel::WhatsappOfficial,
        'credentials' => [
            'business_account_id' => 'WABA123',
            'access_token' => 'TOKEN',
            'phone_number_id' => 'PHONE123',
        ],
    ]);
}

test('list returns the templates from the WABA', function () {
    Http::fake([
        'graph.facebook.com/*/message_templates*' => Http::response([
            'data' => [
                ['name' => 'welcome', 'status' => 'APPROVED', 'language' => 'pt_BR'],
                ['name' => 'promo', 'status' => 'PENDING', 'language' => 'en'],
            ],
        ], 200),
    ]);

    $templates = (new WhatsappTemplateService())->list(templateConnection());

    expect($templates)->toHaveCount(2)
        ->and($templates[0]['name'])->toBe('welcome');

    Http::assertSent(fn ($request) =>
        str_contains($request->url(), 'WABA123/message_templates')
        && $request->hasHeader('Authorization', 'Bearer TOKEN')
    );
});

test('create posts the template definition and returns Metas response', function () {
    Http::fake([
        'graph.facebook.com/*/message_templates' => Http::response([
            'id' => '999', 'status' => 'PENDING', 'category' => 'MARKETING',
        ], 200),
    ]);

    $result = (new WhatsappTemplateService())->create(templateConnection(), [
        'name' => 'promo',
        'category' => 'MARKETING',
        'language' => 'pt_BR',
        'components' => [['type' => 'BODY', 'text' => 'Hello {{1}}']],
    ]);

    expect($result['id'])->toBe('999')->and($result['status'])->toBe('PENDING');

    Http::assertSent(fn ($request) =>
        $request->method() === 'POST'
        && $request['name'] === 'promo'
        && $request['category'] === 'MARKETING'
    );
});

test('delete sends a DELETE with the template name', function () {
    Http::fake([
        'graph.facebook.com/*/message_templates*' => Http::response(['success' => true], 200),
    ]);

    (new WhatsappTemplateService())->delete(templateConnection(), 'promo');

    Http::assertSent(fn ($request) =>
        $request->method() === 'DELETE' && $request['name'] === 'promo'
    );
});

test('surfaces a Graph error as an exception', function () {
    Http::fake([
        'graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'Invalid OAuth access token'],
        ], 401),
    ]);

    expect(fn () => (new WhatsappTemplateService())->list(templateConnection()))
        ->toThrow(RuntimeException::class, 'Invalid OAuth access token');
});

test('throws when the connection is missing credentials', function () {
    $connection = new Connection([
        'channel' => Channel::WhatsappOfficial,
        'credentials' => ['phone_number_id' => 'PHONE123'], // no waba / token
    ]);

    expect(fn () => (new WhatsappTemplateService())->list($connection))
        ->toThrow(RuntimeException::class, 'missing business_account_id or access_token');
});
