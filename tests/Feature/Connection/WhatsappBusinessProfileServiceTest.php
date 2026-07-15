<?php

use App\Models\Connection;
use App\Services\Connection\WhatsApp\WhatsappBusinessProfileService;
use Illuminate\Support\Facades\Http;

function profileConnection(): Connection
{
    return new Connection([
        'credentials' => [
            'access_token' => 'TOKEN',
            'phone_number_id' => 'PN99',
        ],
    ]);
}

test('gets the business profile from the data envelope', function () {
    Http::fake([
        '*/PN99/whatsapp_business_profile*' => Http::response([
            'data' => [[
                'about' => 'We sell coffee',
                'email' => 'hi@shop.com',
                'websites' => ['https://shop.com'],
            ]],
        ], 200),
    ]);

    $profile = (new WhatsappBusinessProfileService())->get(profileConnection());

    expect($profile['about'])->toBe('We sell coffee')
        ->and($profile['email'])->toBe('hi@shop.com');
});

test('update only sends whitelisted fields plus messaging_product, then returns fresh profile', function () {
    Http::fake([
        '*/whatsapp_business_profile' => Http::response(['success' => true], 200),        // POST update
        '*/whatsapp_business_profile?*' => Http::response(['data' => [['about' => 'New']]], 200), // GET refresh
    ]);

    $profile = (new WhatsappBusinessProfileService())->update(profileConnection(), [
        'about' => 'New',
        'description' => 'Desc',
        'hacker_field' => 'nope',   // must be stripped
    ]);

    expect($profile['about'])->toBe('New');

    Http::assertSent(function ($request) {
        if ($request->method() !== 'POST') {
            return false;
        }
        $body = $request->data();
        return ($body['messaging_product'] ?? null) === 'whatsapp'
            && ($body['about'] ?? null) === 'New'
            && ($body['description'] ?? null) === 'Desc'
            && !array_key_exists('hacker_field', $body);
    });
});

test('throws when credentials are missing', function () {
    $connection = new Connection(['credentials' => ['access_token' => 'x']]); // no phone_number_id

    expect(fn () => (new WhatsappBusinessProfileService())->get($connection))
        ->toThrow(RuntimeException::class);
});
