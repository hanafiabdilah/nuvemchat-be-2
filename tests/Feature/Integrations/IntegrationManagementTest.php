<?php

use App\Enums\Flow\NodeType;
use App\Enums\Integration\IntegrationProvider;
use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\IntegrationFixtures as Fx;

uses(RefreshDatabase::class);

function openPixProviderFake(int $verifyStatus = 200): void
{
    Http::fake(function (Request $request) use ($verifyStatus) {
        if (! str_contains($request->url(), 'api.openpix.com.br/api/v1/webhook')) {
            return Http::response([], 404);
        }

        return match ($request->method()) {
            'GET' => $verifyStatus === 200
                ? Http::response(['webhooks' => []])
                : Http::response(['error' => 'appID inválido'], $verifyStatus),
            'POST' => Http::response(['webhook' => ['id' => 'wh_'.$request['webhook']['event']]]),
            'DELETE' => Http::response(['status' => 'OK']),
            default => Http::response([], 405),
        };
    });
}

function openPixPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'provider' => 'openpix',
        'name' => 'Loja principal',
        'credentials' => Fx::CREDENTIALS['openpix'],
        'settings' => ['sandbox' => false],
    ], $overrides);
}

test('connecting OpenPix verifies the key and registers the payment webhook', function () {
    openPixProviderFake();
    $user = Fx::user();

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/integrations', openPixPayload())
        ->assertCreated()
        ->assertJsonPath('data.provider', 'openpix')
        ->assertJsonPath('data.category', 'payment')
        ->assertJsonPath('data.webhook.registered', true);

    $integration = Integration::sole();

    expect($integration->tenant_id)->toBe($user->tenant_id)
        ->and($integration->meta['webhook']['webhook_ids'])->toHaveCount(2);

    // Both events, pointed at this account's own URL.
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request['webhook']['event'] === 'OPENPIX:CHARGE_COMPLETED'
        && $request['webhook']['url'] === $integration->webhookUrl());

    // The key went to OpenPix and nowhere else: not back in the response, not
    // in plain text in the database.
    $appId = Fx::CREDENTIALS['openpix']['app_id'];
    expect($response->getContent())->not->toContain($appId)
        ->and($response->json('data.credentials.app_id'))->toBe('••••'.substr($appId, -4))
        ->and(DB::table('integrations')->value('credentials'))->not->toContain($appId);
});

test('a key the provider refuses is never stored, and the refusal is in our words', function () {
    openPixProviderFake(verifyStatus: 401);

    $this->actingAs(Fx::user(), 'sanctum')->postJson('/api/integrations', openPixPayload())
        ->assertUnprocessable()
        ->assertJsonPath('code', 'integration_credentials_invalid')
        ->assertJsonMissing(['message' => 'appID inválido']);

    expect(Integration::count())->toBe(0);
});

test('editing without retyping the secret keeps the stored one and does not reconnect', function () {
    Http::fake();
    $user = Fx::user();
    $integration = Fx::integration($user->tenant, IntegrationProvider::OpenPix);

    $this->actingAs($user, 'sanctum')->putJson("/api/integrations/{$integration->id}", [
        'name' => 'Loja 2',
        'credentials' => ['app_id' => ''],
    ])->assertOk()->assertJsonPath('data.name', 'Loja 2');

    Http::assertNothingSent();
    expect($integration->fresh()->credential('app_id'))->toBe(Fx::CREDENTIALS['openpix']['app_id']);
});

test('connecting Mercado Pago records whose account it is', function () {
    Http::fake(['api.mercadopago.com/users/me' => Http::response([
        'id' => 99887766, 'nickname' => 'LOJATESTE', 'email' => 'loja@example.com', 'site_id' => 'MLB',
    ])]);

    $this->actingAs(Fx::user(), 'sanctum')->postJson('/api/integrations', [
        'provider' => 'mercadopago',
        'name' => 'Mercado Pago',
        'credentials' => Fx::CREDENTIALS['mercadopago'],
    ])->assertCreated()
        ->assertJsonPath('data.account.account_name', 'LOJATESTE')
        ->assertJsonPath('data.payment_methods', ['pix', 'checkout'])
        // Mercado Pago carries the URL on every charge: nothing to register.
        ->assertJsonPath('data.webhook.mode', 'per_payment');
});

test('a Meta pixel must be one the token can reach', function () {
    Http::fake(['graph.facebook.com/*' => Http::response([
        'error' => ['message' => "Unsupported get request. Object with ID '123456789012345' does not exist", 'code' => 100],
    ], 400)]);

    $this->actingAs(Fx::user(), 'sanctum')->postJson('/api/integrations', [
        'provider' => 'meta_pixel',
        'name' => 'Pixel da loja',
        'credentials' => Fx::CREDENTIALS['meta_pixel'],
        'settings' => Fx::SETTINGS['meta_pixel'],
    ])->assertUnprocessable()->assertJsonPath('code', 'pixel_not_found');
});

test('a malformed GA4 measurement id is refused before anyone is called', function () {
    Http::fake();

    $this->actingAs(Fx::user(), 'sanctum')->postJson('/api/integrations', [
        'provider' => 'google_analytics',
        'name' => 'GA4',
        'credentials' => Fx::CREDENTIALS['google_analytics'],
        'settings' => ['measurement_id' => 'UA-12345-1'],
    ])->assertUnprocessable()->assertJsonValidationErrors('settings.measurement_id');

    Http::assertNothingSent();
});

test('deleting OpenPix removes the webhooks it registered', function () {
    openPixProviderFake();
    $user = Fx::user();
    $integration = Fx::integration($user->tenant, IntegrationProvider::OpenPix, [
        'meta' => ['webhook' => ['webhook_ids' => ['OPENPIX:CHARGE_COMPLETED' => 'wh_1']]],
    ]);

    $this->actingAs($user, 'sanctum')->deleteJson("/api/integrations/{$integration->id}")->assertOk();

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/api/v1/webhook/wh_1'));
    expect(Integration::count())->toBe(0);
});

test('one workspace cannot see or touch another workspace\'s integration', function () {
    Http::fake();
    $foreign = Fx::integration(Fx::tenant(), IntegrationProvider::OpenPix);
    $user = Fx::user();

    $this->actingAs($user, 'sanctum')->putJson("/api/integrations/{$foreign->id}", ['name' => 'x'])->assertNotFound();
    $this->actingAs($user, 'sanctum')->deleteJson("/api/integrations/{$foreign->id}")->assertNotFound();
    $this->actingAs($user, 'sanctum')->getJson('/api/integrations')->assertOk()->assertJsonCount(0, 'data');
});

test('editing flows is enough to list integrations, never to connect one or see its webhook', function () {
    $builder = Fx::user(permissions: ['flows.update']);
    Fx::integration($builder->tenant, IntegrationProvider::OpenPix);

    $this->actingAs($builder, 'sanctum')->getJson('/api/integrations?category=payment')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.webhook', null);

    $this->actingAs($builder, 'sanctum')->postJson('/api/integrations', openPixPayload())->assertForbidden();
});

test('the list says which flows use each integration', function () {
    $user = Fx::user();
    $integration = Fx::integration($user->tenant, IntegrationProvider::OpenPix);
    $flow = Fx::flow($user->tenant, 'Checkout');
    Fx::node($flow, NodeType::Payment, ['integration_id' => $integration->id, 'amount' => '10']);

    $this->actingAs($user, 'sanctum')->getJson('/api/integrations')
        ->assertOk()
        ->assertJsonPath('data.0.used_by_flows.0.name', 'Checkout')
        ->assertJsonPath('catalog.0.provider', 'openpix')
        ->assertJsonPath('categories.0.key', 'payment');
});
