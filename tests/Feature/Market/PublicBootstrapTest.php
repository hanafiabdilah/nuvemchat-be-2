<?php

use App\Enums\Market\MarketStatus;
use App\Models\Market;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PlatformUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function bootstrapIndonesiaMarket(): Market
{
    $market = Market::create([
        'code' => 'ID',
        'name' => 'Indonesia',
        'currency' => 'IDR',
        'default_locale' => 'id',
        'default_timezone' => 'Asia/Jakarta',
        'phone_country' => '62',
        'status' => MarketStatus::Draft,
    ]);

    $market->domains()->create(['domain' => 'app.pingly.co.id', 'is_primary' => true]);

    return $market;
}

function bootstrapUser(?string $marketCode = null): User
{
    $user = User::factory()->create();
    $tenant = new Tenant(['user_id' => $user->id]);

    if ($marketCode !== null) {
        $tenant->forceFill(['market_code' => $marketCode]);
    }

    $tenant->save();
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $user->fresh();
}

test('a country domain answers, without sign-in, with the market a signup there would join', function () {
    bootstrapIndonesiaMarket();

    $this->getJson('https://app.pingly.co.id/api/public/bootstrap')
        ->assertOk()
        ->assertJsonPath('data.market.code', 'ID')
        ->assertJsonPath('data.market.currency', 'IDR')
        ->assertJsonPath('data.market.default_locale', 'id')
        ->assertJsonPath('data.market.default_timezone', 'Asia/Jakarta')
        ->assertJsonPath('data.market.phone_country', '62')
        ->assertJsonPath('data.multiple_markets', true);
});

test('the platform domain answers with the default market, and one market is nothing to tell apart', function () {
    $this->getJson('https://chat.pingly.com.br/api/public/bootstrap')
        ->assertOk()
        ->assertJsonPath('data.market.code', 'BR')
        ->assertJsonPath('data.market.currency', 'BRL')
        ->assertJsonPath('data.multiple_markets', false);
});

test('a draft market is not announced as a draft', function () {
    // Served to anyone who opens a signup page; launch plans are not theirs.
    bootstrapIndonesiaMarket();

    $this->getJson('https://app.pingly.co.id/api/public/bootstrap')
        ->assertOk()
        ->assertJsonMissingPath('data.market.status');
});

test('the platform url reported is the pinned one, not the domain that asked', function () {
    config(['app.platform_url' => 'https://chat.pingly.com.br']);
    PlatformUrl::apply(app('url'));
    bootstrapIndonesiaMarket();

    $this->getJson('https://app.pingly.co.id/api/public/bootstrap')
        ->assertOk()
        ->assertJsonPath('data.platform_url', 'https://chat.pingly.com.br');
});

test('the signed-in user payload carries the workspace market', function () {
    $this->actingAs(bootstrapUser())
        ->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('data.market.code', 'BR')
        ->assertJsonPath('data.market.currency', 'BRL');
});

test('the user payload follows the workspace, not the domain the dashboard is open on', function () {
    // An Indonesian workspace opened through the Brazilian domain is still
    // Indonesian: its money is in rupiah wherever it is looked at from.
    bootstrapIndonesiaMarket();

    $this->actingAs(bootstrapUser('ID'))
        ->getJson('https://chat.pingly.com.br/api/user')
        ->assertOk()
        ->assertJsonPath('data.market.code', 'ID')
        ->assertJsonPath('data.market.currency', 'IDR');
});
