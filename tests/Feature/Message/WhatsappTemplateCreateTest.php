<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Models\Connection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function waTemplateCreateUser(): User
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $role = Role::findOrCreate('template-author-' . $tenant->id, 'web');
    foreach (['templates.view', 'templates.create', 'templates.delete', 'templates.send'] as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $user->assignRole($role);

    return $user->fresh();
}

function waTemplateConnection(User $user, string $wabaId, string $name): Connection
{
    return Connection::create([
        'tenant_id' => $user->tenant_id,
        'channel' => Channel::WhatsappOfficial,
        'name' => $name,
        'color' => '#25D366',
        'status' => ConnectionStatus::Active,
        'credentials' => [
            'phone_number_id' => 'phone-' . $name,
            'access_token' => 'wa-token',
            'business_account_id' => $wabaId,
        ],
    ]);
}

/** A minimal body-only template payload, with whatever extras a test needs. */
function waTemplatePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'order_update',
        'category' => 'UTILITY',
        'language' => 'pt_BR',
        'components' => [['type' => 'BODY', 'text' => 'Hi']],
    ], $overrides);
}

test('creating for several numbers hits each WhatsApp Business Account once', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => '1', 'status' => 'PENDING'])]);

    $user = waTemplateCreateUser();
    // Two numbers on one WABA, a third on its own.
    $first = waTemplateConnection($user, 'waba-A', 'Sales');
    $second = waTemplateConnection($user, 'waba-A', 'Support');
    $third = waTemplateConnection($user, 'waba-B', 'Billing');

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/templates', waTemplatePayload([
            'connection_ids' => [$first->id, $second->id, $third->id],
        ]))
        ->assertStatus(201);

    // Two accounts, so two calls — not three. A template is visible to every
    // number on the account that holds it.
    expect($response->json('created'))->toBe(2)
        ->and($response->json('data'))->toHaveCount(2);

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/waba-A/message_templates'));
    Http::assertSent(fn ($request) => str_contains($request->url(), '/waba-B/message_templates'));
});

test('one account failing still creates the template on the others', function () {
    $user = waTemplateCreateUser();
    $ok = waTemplateConnection($user, 'waba-A', 'Sales');
    $clash = waTemplateConnection($user, 'waba-B', 'Billing');

    Http::fake([
        'graph.facebook.com/*/waba-A/message_templates' => Http::response(['id' => '1', 'status' => 'PENDING']),
        'graph.facebook.com/*/waba-B/message_templates' => Http::response(
            ['error' => ['message' => 'Template name already exists']],
            400
        ),
    ]);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/templates', waTemplatePayload(['connection_ids' => [$ok->id, $clash->id]]))
        ->assertStatus(201);

    expect($response->json('created'))->toBe(1);

    $results = collect($response->json('data'));
    expect($results->firstWhere('connection_name', 'Sales')['status'])->toBe('created')
        ->and($results->firstWhere('connection_name', 'Billing')['status'])->toBe('failed')
        ->and($results->firstWhere('connection_name', 'Billing')['message'])->toBe('Template name already exists');
});

test('every account failing is reported as a failure, not a success', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Nope']], 400)]);

    $user = waTemplateCreateUser();
    $connection = waTemplateConnection($user, 'waba-A', 'Sales');

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/templates', waTemplatePayload(['connection_ids' => [$connection->id]]))
        ->assertStatus(422)
        ->assertJsonPath('created', 0);
});

test('named variables and the category-change flag reach Meta only when asked for', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => '1'])]);

    $user = waTemplateCreateUser();
    $connection = waTemplateConnection($user, 'waba-A', 'Sales');

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/templates', waTemplatePayload([
            'connection_ids' => [$connection->id],
            'parameter_format' => 'NAMED',
            'allow_category_change' => true,
        ]))
        ->assertStatus(201);

    Http::assertSent(fn ($request) => $request->data()['parameter_format'] === 'NAMED'
        && $request->data()['allow_category_change'] === true);
});

test('the default positional format is left off the request entirely', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => '1'])]);

    $user = waTemplateCreateUser();
    $connection = waTemplateConnection($user, 'waba-A', 'Sales');

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/templates', waTemplatePayload([
            'connection_ids' => [$connection->id],
            'parameter_format' => 'POSITIONAL',
            'allow_category_change' => false,
        ]))
        ->assertStatus(201);

    // Meta already assumes positional; sending the flags would only pin
    // behaviour the caller never actually chose.
    Http::assertSent(fn ($request) => !array_key_exists('parameter_format', $request->data())
        && !array_key_exists('allow_category_change', $request->data()));
});

test('with no connection chosen it falls back to the first active number', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => '1'])]);

    $user = waTemplateCreateUser();
    waTemplateConnection($user, 'waba-A', 'Sales');

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/templates', waTemplatePayload())
        ->assertStatus(201);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/waba-A/message_templates'));
});
