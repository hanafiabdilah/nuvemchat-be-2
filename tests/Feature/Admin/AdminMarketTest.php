<?php

use App\Enums\Market\MarketStatus;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Market;
use App\Models\MarketDomain;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Market\MarketResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** @param list<string> $permissions */
function marketsAdmin(array $permissions = ['bo.markets.manage']): Admin
{
    $role = Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();

    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    $admin = Admin::factory()->create();
    $admin->assignRole($role);

    return $admin->fresh();
}

function workspaceInMarket(string $code): Tenant
{
    $owner = User::factory()->create();

    $tenant = new Tenant(['user_id' => $owner->id]);
    $tenant->market_code = $code;
    $tenant->save();

    $owner->forceFill(['tenant_id' => $tenant->id])->save();

    return $tenant;
}

function adminIndonesiaMarket(): Market
{
    return Market::create([
        'code' => 'ID',
        'name' => 'Indonesia',
        'currency' => 'IDR',
        'default_locale' => 'id',
        'default_timezone' => 'Asia/Jakarta',
        'phone_country' => '62',
        'status' => 'draft',
    ]);
}

it('requires the markets permission', function () {
    $admin = marketsAdmin([]);

    $this->actingAs($admin)->getJson('/api/admin/markets')->assertForbidden();
    $this->actingAs($admin)->postJson('/api/admin/markets', ['code' => 'ID'])->assertForbidden();
});

it('lists markets with what is locked about them', function () {
    workspaceInMarket('BR');

    $market = $this->actingAs(marketsAdmin())->getJson('/api/admin/markets')
        ->assertOk()
        ->json('data.0');

    expect($market)
        ->code->toBe('BR')
        ->status->toBe('active')
        ->is_default->toBeTrue()
        ->tenants_count->toBe(1)
        ->currency_locked->toBeTrue()
        ->deletable->toBeFalse();
});

it('serves the country catalog so nothing on the form is typed', function () {
    $meta = $this->actingAs(marketsAdmin())->getJson('/api/admin/markets/meta')->assertOk()->json();

    $indonesia = collect($meta['countries'])->firstWhere('code', 'ID');

    expect($indonesia)
        ->currency->toBe('IDR')
        ->calling_code->toBe('62')
        ->and($indonesia['timezones'])->toContain('Asia/Jakarta')
        ->and(collect($meta['locales'])->pluck('code')->all())->toBe(['pt_BR', 'en', 'id'])
        ->and($meta['currencies'])->toContain('IDR', 'BRL')
        ->and($meta['statuses'])->toBe(['draft', 'soft_launch', 'active', 'paused'])
        ->and($meta['default_market'])->toBe('BR');
});

it('opens a market from a country and derives the calling code', function () {
    $this->actingAs(marketsAdmin())->postJson('/api/admin/markets', [
        'code' => 'id',
        'name' => 'Indonesia',
        'currency' => 'IDR',
        'default_locale' => 'id',
        'default_timezone' => 'Asia/Jakarta',
        // Not a choice — ignored even when sent.
        'phone_country' => '999',
    ])->assertCreated()->assertJsonPath('data.code', 'ID');

    $market = Market::findOrFail('ID');

    expect($market)
        ->phone_country->toBe('62')
        ->status->toBe(MarketStatus::Draft)
        ->and(AuditLog::where('action', 'markets.create')->count())->toBe(1);
});

it('refuses a country outside the catalog or one that already has a market', function (string $code) {
    $this->actingAs(marketsAdmin())->postJson('/api/admin/markets', [
        'code' => $code,
        'name' => 'X',
        'currency' => 'USD',
        'default_locale' => 'en',
        'default_timezone' => 'UTC',
    ])->assertUnprocessable()->assertJsonValidationErrors('code');
})->with(['ZZ', 'BR']);

it('refuses a language, currency or timezone outside the lists', function () {
    $this->actingAs(marketsAdmin())->postJson('/api/admin/markets', [
        'code' => 'ID',
        'name' => 'Indonesia',
        'currency' => 'XYZ',
        'default_locale' => 'fr',
        'default_timezone' => 'Mars/Olympus',
    ])->assertUnprocessable()->assertJsonValidationErrors(['currency', 'default_locale', 'default_timezone']);
});

it('updates a market and records what changed', function () {
    adminIndonesiaMarket();

    $this->actingAs(marketsAdmin())->putJson('/api/admin/markets/ID', [
        'status' => 'soft_launch',
        'default_timezone' => 'Asia/Makassar',
    ])->assertOk()->assertJsonPath('data.status', 'soft_launch');

    $log = AuditLog::where('action', 'markets.update')->firstOrFail();

    expect(Market::findOrFail('ID'))
        ->status->toBe(MarketStatus::SoftLaunch)
        ->default_timezone->toBe('Asia/Makassar')
        ->and($log->metadata['before'])->toMatchArray(['status' => 'draft', 'default_timezone' => 'Asia/Jakarta'])
        ->and($log->metadata['after'])->toMatchArray(['status' => 'soft_launch', 'default_timezone' => 'Asia/Makassar']);
});

it('fixes the currency once a market has workspaces', function () {
    workspaceInMarket('BR');
    adminIndonesiaMarket();
    $admin = marketsAdmin();

    $this->actingAs($admin)->putJson('/api/admin/markets/BR', ['currency' => 'USD'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('currency');

    $this->actingAs($admin)->putJson('/api/admin/markets/ID', ['currency' => 'USD'])
        ->assertOk()
        ->assertJsonPath('data.currency', 'USD');

    expect(Market::findOrFail('BR')->currency)->toBe('BRL');
});

it('never deletes the default market or one with workspaces', function () {
    adminIndonesiaMarket();
    workspaceInMarket('ID');
    $admin = marketsAdmin();

    $this->actingAs($admin)->deleteJson('/api/admin/markets/BR')
        ->assertUnprocessable()
        ->assertJsonPath('code', 'market_is_default');

    $this->actingAs($admin)->deleteJson('/api/admin/markets/ID')
        ->assertUnprocessable()
        ->assertJsonPath('code', 'market_has_workspaces');

    expect(Market::whereKey(['BR', 'ID'])->count())->toBe(2);
});

it('deletes an empty market together with its domains', function () {
    adminIndonesiaMarket();
    MarketDomain::create(['market_code' => 'ID', 'domain' => 'chat.pingly.id', 'is_primary' => true]);

    expect(MarketResolver::codeForHost('chat.pingly.id'))->toBe('ID');

    $this->actingAs(marketsAdmin())->deleteJson('/api/admin/markets/ID')->assertOk();

    expect(Market::find('ID'))->toBeNull()
        ->and(MarketDomain::count())->toBe(0)
        ->and(MarketResolver::codeForHost('chat.pingly.id'))->toBe('BR');
});

it('adds domains in the spelling requests are matched in', function () {
    adminIndonesiaMarket();
    $admin = marketsAdmin();

    $this->actingAs($admin)->postJson('/api/admin/markets/ID/domains', ['domain' => 'HTTPS://Chat.Pingly.ID:443/'])
        ->assertCreated()
        ->assertJsonPath('data.domains.0.domain', 'chat.pingly.id')
        ->assertJsonPath('data.domains.0.is_primary', true);

    $this->actingAs($admin)->postJson('/api/admin/markets/ID/domains', ['domain' => 'app.pingly.co.id'])
        ->assertCreated();

    expect(MarketDomain::where('domain', 'app.pingly.co.id')->value('is_primary'))->toBeFalse()
        ->and(MarketResolver::codeForHost('chat.pingly.id'))->toBe('ID')
        ->and(MarketResolver::codeForHost('app.pingly.co.id'))->toBe('ID');
});

it('refuses the platform host, an IP address and a domain another market holds', function (string $domain) {
    config(['app.platform_url' => 'https://chat.pingly.com.br']);
    adminIndonesiaMarket();
    MarketDomain::create(['market_code' => 'BR', 'domain' => 'loja.example.com.br']);

    $this->actingAs(marketsAdmin())->postJson('/api/admin/markets/ID/domains', ['domain' => $domain])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('domain');

    expect(MarketDomain::where('market_code', 'ID')->count())->toBe(0);
})->with(['chat.pingly.com.br', '10.0.0.1', 'loja.example.com.br', 'localhost', 'not a domain']);

it('keeps exactly one primary domain', function () {
    adminIndonesiaMarket();
    $first = MarketDomain::create(['market_code' => 'ID', 'domain' => 'chat.pingly.id', 'is_primary' => true]);
    $second = MarketDomain::create(['market_code' => 'ID', 'domain' => 'app.pingly.co.id', 'is_primary' => false]);
    $admin = marketsAdmin();

    $this->actingAs($admin)->putJson("/api/admin/markets/ID/domains/{$second->id}/primary")->assertOk();

    expect($first->fresh()->is_primary)->toBeFalse()
        ->and($second->fresh()->is_primary)->toBeTrue();

    // Removing the primary hands the address to the one that is left.
    $this->actingAs($admin)->deleteJson("/api/admin/markets/ID/domains/{$second->id}")->assertOk();

    expect($first->fresh()->is_primary)->toBeTrue()
        ->and(MarketResolver::codeForHost('app.pingly.co.id'))->toBe('BR');
});

it('only resolves a domain inside its own market', function () {
    adminIndonesiaMarket();
    $brazilian = MarketDomain::create(['market_code' => 'BR', 'domain' => 'loja.example.com.br']);

    $this->actingAs(marketsAdmin())->deleteJson("/api/admin/markets/ID/domains/{$brazilian->id}")->assertNotFound();

    expect($brazilian->fresh())->not->toBeNull();
});

it('checks that a domain reaches the platform and answers as its market', function () {
    adminIndonesiaMarket();
    $domain = MarketDomain::create(['market_code' => 'ID', 'domain' => 'chat.pingly.id', 'is_primary' => true]);
    $admin = marketsAdmin();

    Http::fakeSequence('https://chat.pingly.id/*')
        ->push(['market' => ['code' => 'ID']])
        ->push(['market' => ['code' => 'BR']])
        ->push('Not found', 404);

    $url = "/api/admin/markets/ID/domains/{$domain->id}/check";

    $this->actingAs($admin)->postJson($url)->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('served_market', 'ID');

    $this->actingAs($admin)->postJson($url)->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('problem', 'other_market')
        ->assertJsonPath('served_market', 'BR');

    $this->actingAs($admin)->postJson($url)->assertOk()
        ->assertJsonPath('problem', 'http')
        ->assertJsonPath('http_status', 404);
});

it('reports a domain that cannot be reached', function () {
    adminIndonesiaMarket();
    $domain = MarketDomain::create(['market_code' => 'ID', 'domain' => 'chat.pingly.id', 'is_primary' => true]);

    Http::fake(['*' => Http::failedConnection('cURL error 6: Could not resolve host')]);

    $this->actingAs(marketsAdmin())->postJson("/api/admin/markets/ID/domains/{$domain->id}/check")
        ->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('problem', 'unreachable');
});

it('filters customers by market', function () {
    adminIndonesiaMarket();
    workspaceInMarket('BR');
    $indonesian = workspaceInMarket('ID');

    $response = $this->actingAs(marketsAdmin(['bo.customers.view']))
        ->getJson('/api/admin/customers?market=id')
        ->assertOk();

    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('data.0.id'))->toBe($indonesian->id)
        ->and($response->json('data.0.market_code'))->toBe('ID');
});
