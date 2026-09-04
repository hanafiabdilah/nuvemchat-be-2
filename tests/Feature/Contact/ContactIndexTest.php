<?php

use App\Enums\Connection\Channel;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function contactBookUser(): User
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);

    $user->forceFill(['tenant_id' => $tenant->id])->save();
    $user->setRelation('tenant', $tenant);

    Sanctum::actingAs($user);

    return $user;
}

function makeContact(int $tenantId, array $attributes = []): Contact
{
    return Contact::create(array_merge([
        'tenant_id' => $tenantId,
        'channel' => Channel::WhatsappOfficial,
        'name' => 'Cliente',
        'username' => null,
        'external_id' => '5511999990000',
        'is_group' => false,
    ], $attributes));
}

test('it lists the workspace contacts', function () {
    $this->withoutMiddleware();
    $user = contactBookUser();
    makeContact($user->tenant_id, ['name' => 'Ana']);

    $this->getJson('/api/contacts')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

/**
 * ⚠️ Regression, and the reason this file exists.
 *
 * The search used `->where(name)->orWhere(username)->orWhere(external_id)`
 * without a group. SQL binds AND tighter than OR, so the tenant and is_group
 * conditions only guarded the first branch: a search that matched on username
 * or external_id returned contacts belonging to every other workspace.
 */
test('searching by username never reaches another workspace', function () {
    $this->withoutMiddleware();
    $user = contactBookUser();

    $stranger = User::factory()->create();
    $strangerTenant = Tenant::create(['user_id' => $stranger->id]);
    $stranger->forceFill(['tenant_id' => $strangerTenant->id])->save();

    makeContact($strangerTenant->id, ['name' => 'Outro', 'username' => 'unicorn']);

    $this->getJson('/api/contacts?search=unicorn')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('searching by external id never reaches another workspace', function () {
    $this->withoutMiddleware();
    contactBookUser();

    $stranger = User::factory()->create();
    $strangerTenant = Tenant::create(['user_id' => $stranger->id]);
    $stranger->forceFill(['tenant_id' => $strangerTenant->id])->save();

    makeContact($strangerTenant->id, ['name' => 'Outro', 'external_id' => '5599123456789']);

    $this->getJson('/api/contacts?search=5599123456789')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('it filters on several channels at once', function () {
    $this->withoutMiddleware();
    $user = contactBookUser();

    makeContact($user->tenant_id, ['name' => 'Zap', 'channel' => Channel::WhatsappOfficial]);
    makeContact($user->tenant_id, ['name' => 'Tele', 'channel' => Channel::Telegram]);
    makeContact($user->tenant_id, ['name' => 'Insta', 'channel' => Channel::Instagram]);

    $response = $this->getJson('/api/contacts?channel[]=telegram&channel[]=instagram')->assertOk();

    expect(collect($response->json('data'))->pluck('name')->sort()->values()->all())
        ->toBe(['Insta', 'Tele']);
});

test('a single channel value still works for the broadcast picker', function () {
    $this->withoutMiddleware();
    $user = contactBookUser();

    makeContact($user->tenant_id, ['name' => 'Zap', 'channel' => Channel::WhatsappOfficial]);
    makeContact($user->tenant_id, ['name' => 'Tele', 'channel' => Channel::Telegram]);

    $response = $this->getJson('/api/contacts?channel=telegram')->assertOk();

    expect($response->json('data.0.name'))->toBe('Tele');
});

test('it lists only the contacts who opted out of campaigns', function () {
    $this->withoutMiddleware();
    $user = contactBookUser();

    makeContact($user->tenant_id, ['name' => 'Reachable']);
    makeContact($user->tenant_id, ['name' => 'Quiet', 'broadcast_opted_out_at' => now()]);

    $response = $this->getJson('/api/contacts?opted_out=1')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.name'))->toBe('Quiet');
});

test('it lists only the contacts a campaign can still reach', function () {
    $this->withoutMiddleware();
    $user = contactBookUser();

    makeContact($user->tenant_id, ['name' => 'Reachable']);
    makeContact($user->tenant_id, ['name' => 'Quiet', 'broadcast_opted_out_at' => now()]);

    $response = $this->getJson('/api/contacts?opted_out=0')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.name'))->toBe('Reachable');
});

test('an absent opt-out filter returns both', function () {
    $this->withoutMiddleware();
    $user = contactBookUser();

    makeContact($user->tenant_id, ['name' => 'Reachable']);
    makeContact($user->tenant_id, ['name' => 'Quiet', 'broadcast_opted_out_at' => now()]);

    $this->getJson('/api/contacts')->assertOk()->assertJsonCount(2, 'data');
});

test('group contacts stay out of the contact book', function () {
    $this->withoutMiddleware();
    $user = contactBookUser();

    makeContact($user->tenant_id, ['name' => 'Pessoa']);
    makeContact($user->tenant_id, ['name' => 'Grupo', 'is_group' => true]);

    // Including when a search matches them: the group guard is on the outer
    // query, which is exactly what the ungrouped OR used to escape.
    $this->getJson('/api/contacts?search=Grupo')->assertOk()->assertJsonCount(0, 'data');
});
