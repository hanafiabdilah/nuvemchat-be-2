<?php

use App\Enums\Numbers\VirtualNumberStatus;
use App\Events\VirtualNumberSmsReceived;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VirtualNumber;
use App\Models\VirtualNumberMessage;
use App\Services\VirtualNumbers\ApiwayNumbersConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

/**
 * How a code reaches the person waiting for it.
 *
 * API Way registers one webhook per account and the platform has one account,
 * so every tenant's codes arrive at the same URL and the routing is entirely
 * local. The same SMS can also be pulled by the poll behind the refresh button,
 * which is why storing it twice has to be impossible rather than unlikely.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Setting::set(ApiwayNumbersConfig::KEY_TOKEN, '12|portal-token');
    Setting::set(ApiwayNumbersConfig::KEY_WEBHOOK_SECRET, 'webhook-secret');
    cache()->forget('apiway-numbers:catalog');
});

function smsTenant(): Tenant
{
    foreach (['numbers.view', 'numbers.manage'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create([
        'email' => 'sms-'.uniqid().'@example.test',
        'whatsapp_verified_at' => now(),
    ]);
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();
    $user->givePermissionTo(['numbers.view', 'numbers.manage']);

    return $tenant->fresh();
}

function smsNumber(Tenant $tenant, array $overrides = []): VirtualNumber
{
    return VirtualNumber::create(array_merge([
        'tenant_id' => $tenant->id,
        'provider_number_id' => 128,
        'msisdn' => '5511999998888',
        'app' => 'whatsapp',
        'ddd' => '11',
        'region' => 'Sao Paulo',
        'status' => VirtualNumberStatus::Active,
        'cost_cents' => 3290,
        'price_cents' => 4606,
        'purchased_at' => now(),
        'renews_at' => now()->addDays(30),
    ], $overrides));
}

function smsPayload(array $overrides = []): array
{
    return array_merge([
        'number_id' => 128,
        'msisdn' => '5511999998888',
        'app' => 'whatsapp',
        'from' => 'WhatsApp',
        'message' => 'Seu codigo do WhatsApp: 123-456',
        'code' => '123-456',
        'received_at' => '2026-08-22T12:01:00Z',
        'partner_customer_id' => 'tenant-1',
    ], $overrides);
}

function postSignedWebhook(array $payload, ?string $secret = 'webhook-secret'): \Illuminate\Testing\TestResponse
{
    $body = json_encode($payload);
    $headers = ['X-ApiWay-Event' => 'sms.received', 'Content-Type' => 'application/json'];

    if ($secret !== null) {
        $headers['X-ApiWay-Signature'] = 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    return test()->call('POST', '/webhook/apiway-numbers', [], [], [], transformHeaders($headers), $body);
}

/**
 * Laravel's server-variable form for request headers.
 *
 * Content-Type is the exception to the HTTP_ prefix, and it is not cosmetic:
 * without it the JSON body is never parsed, the payload arrives empty, and the
 * webhook answers 200 having stored nothing.
 */
function transformHeaders(array $headers): array
{
    $server = [];

    foreach ($headers as $key => $value) {
        $name = strtoupper(str_replace('-', '_', $key));
        $server[in_array($name, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $name : 'HTTP_'.$name] = $value;
    }

    return $server;
}

test('a signed sms lands on the number and reaches the dashboard immediately', function () {
    Event::fake([VirtualNumberSmsReceived::class]);

    $tenant = smsTenant();
    $number = smsNumber($tenant);

    postSignedWebhook(smsPayload())->assertOk();

    $message = VirtualNumberMessage::first();
    expect($message)->not->toBeNull()
        ->and($message->virtual_number_id)->toBe($number->id)
        ->and($message->tenant_id)->toBe($tenant->id)
        ->and($message->code)->toBe('123-456')
        ->and($message->body)->toBe('Seu codigo do WhatsApp: 123-456');

    // The list has to show "a code arrived" without opening every number.
    expect($number->fresh()->last_message_at)->not->toBeNull();

    Event::assertDispatched(VirtualNumberSmsReceived::class);
});

test('an unsigned or wrongly signed body is rejected', function () {
    $tenant = smsTenant();
    smsNumber($tenant);

    postSignedWebhook(smsPayload(), secret: null)->assertStatus(401);
    postSignedWebhook(smsPayload(), secret: 'not-the-secret')->assertStatus(401);

    expect(VirtualNumberMessage::count())->toBe(0);
});

test('a body signed with a secret we never stored is rejected, not waved through', function () {
    Setting::set(ApiwayNumbersConfig::KEY_WEBHOOK_SECRET, null);

    $tenant = smsTenant();
    smsNumber($tenant);

    // No legacy traffic to protect here: the webhook only exists once it has
    // been registered, and registering it is what produces the secret.
    postSignedWebhook(smsPayload())->assertStatus(401);
    expect(VirtualNumberMessage::count())->toBe(0);
});

test('a code for a number no workspace owns is acknowledged but never stored', function () {
    smsTenant();

    // 2xx on purpose: a non-2xx earns hours of upstream backoff, which would
    // delay the codes that do belong to somebody.
    postSignedWebhook(smsPayload(['number_id' => 999]))->assertOk();

    expect(VirtualNumberMessage::count())->toBe(0);
});

test('the same sms arriving twice, by either route, is stored once', function () {
    $tenant = smsTenant();
    $number = smsNumber($tenant);

    postSignedWebhook(smsPayload())->assertOk();
    postSignedWebhook(smsPayload())->assertOk();

    expect(VirtualNumberMessage::count())->toBe(1);

    // And again through the poll, which returns the same message with no id.
    Http::fake([
        'portal.apiway.com.br/api/numbers/128/sms' => Http::response([
            ['from' => 'WhatsApp', 'message' => 'Seu codigo do WhatsApp: 123-456', 'code' => '123-456', 'received_at' => '2026-08-22T12:01:00Z'],
            ['from' => 'WhatsApp', 'message' => 'Seu codigo do WhatsApp: 654-321', 'code' => '654-321', 'received_at' => '2026-08-22T12:05:00Z'],
        ]),
    ]);

    Sanctum::actingAs($tenant->user()->first());

    $this->getJson("/api/numbers/{$number->id}?refresh=1")
        ->assertOk()
        ->assertJsonCount(2, 'data.messages');

    expect(VirtualNumberMessage::count())->toBe(2);
});

test('a failed poll still shows what has already arrived', function () {
    $tenant = smsTenant();
    $number = smsNumber($tenant);
    postSignedWebhook(smsPayload())->assertOk();

    Http::fake([
        'portal.apiway.com.br/api/numbers/128/sms' => Http::response(['message' => 'boom'], 502),
    ]);

    Sanctum::actingAs($tenant->user()->first());

    $this->getJson("/api/numbers/{$number->id}?refresh=1")
        ->assertOk()
        ->assertJsonCount(1, 'data.messages')
        ->assertJsonPath('data.messages.0.code', '123-456');
});

test('one workspace cannot read another workspace codes', function () {
    $owner = smsTenant();
    $number = smsNumber($owner);
    postSignedWebhook(smsPayload())->assertOk();

    $stranger = smsTenant();
    Sanctum::actingAs($stranger->user()->first());

    $this->getJson("/api/numbers/{$number->id}")->assertNotFound();
    $this->getJson('/api/numbers')->assertOk()->assertJsonCount(0, 'data');
});
