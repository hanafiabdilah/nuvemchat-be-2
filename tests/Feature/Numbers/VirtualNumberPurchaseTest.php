<?php

use App\Enums\Credit\CreditTransactionType;
use App\Enums\Numbers\VirtualNumberStatus;
use App\Models\CreditTransaction;
use App\Models\CreditWallet;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VirtualNumber;
use App\Services\VirtualNumbers\ApiwayNumbersConfig;
use App\Services\VirtualNumbers\NumberPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

/**
 * Renting a virtual number: the money moves before the number exists, and every
 * way that can fail has to put it back.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Setting::set(ApiwayNumbersConfig::KEY_TOKEN, '12|portal-token');
    Setting::set(NumberPricing::KEY_MARKUP_PCT, '40');
    Setting::set(NumberPricing::KEY_APP_PRICES, json_encode([]));
    cache()->forget('apiway-numbers:catalog');
});

function numbersTenant(int $balanceCents = 0): Tenant
{
    foreach (['numbers.view', 'numbers.manage'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create([
        'email' => 'num-'.uniqid().'@example.test',
        'whatsapp_verified_at' => now(),
    ]);
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();
    $user->givePermissionTo(['numbers.view', 'numbers.manage']);

    if ($balanceCents !== 0) {
        CreditWallet::create(['tenant_id' => $tenant->id, 'balance_cents' => $balanceCents, 'currency' => 'BRL']);
    }

    return $tenant->fresh();
}

/** The catalog, which every path reads before it can price anything. */
function fakeNumbersPortal(array $overrides = []): void
{
    Http::fake(array_merge([
        'portal.apiway.com.br/api/numbers/catalog' => Http::response([
            'apps' => [
                ['id' => 'whatsapp', 'label' => 'WhatsApp'],
                ['id' => 'telegram', 'label' => 'Telegram'],
            ],
            'regions' => ['11' => 'Sao Paulo', '21' => 'Rio de Janeiro'],
            'price_cents' => 3290,
            'currency' => 'BRL',
        ]),
    ], $overrides));
}

function fakeNumberCreated(array $overrides = []): array
{
    return array_merge([
        'id' => 128,
        'msisdn' => '5511999998888',
        'app' => 'whatsapp',
        'ddd' => '11',
        'region' => 'Sao Paulo',
        'status' => 'active',
        'price_cents' => 3290,
        'currency' => 'BRL',
        'partner_customer_id' => 'cliente-123',
        'renews_at' => now()->addDays(30)->toISOString(),
        'created_at' => now()->toISOString(),
    ], $overrides);
}

test('renting a number charges the balance and stores what API Way returned', function () {
    fakeNumbersPortal([
        'portal.apiway.com.br/api/numbers' => Http::response(fakeNumberCreated(), 201),
    ]);

    $tenant = numbersTenant(10_000);
    Sanctum::actingAs($tenant->user()->first());

    $this->postJson('/api/numbers', ['ddd' => '11', 'app' => 'whatsapp'])
        ->assertCreated()
        ->assertJsonPath('data.msisdn', '5511999998888')
        ->assertJsonPath('data.status', 'active')
        // 3290 upstream + the platform's 40% = 4606, and that is what the
        // tenant is told before and charged after.
        ->assertJsonPath('data.price_cents', 4606);

    $row = VirtualNumber::first();
    expect($row->status)->toBe(VirtualNumberStatus::Active)
        ->and($row->provider_number_id)->toBe(128)
        ->and($row->cost_cents)->toBe(3290)
        ->and($row->price_cents)->toBe(4606)
        ->and($row->renews_at)->not->toBeNull();

    expect(app(\App\Services\Credits\CreditService::class)->balanceCents($tenant))->toBe(10_000 - 4606);

    $debit = CreditTransaction::where('reference', "numbers:buy:{$row->id}")->first();
    expect($debit)->not->toBeNull()
        ->and($debit->type)->toBe(CreditTransactionType::Purchase)
        ->and($debit->amount_cents)->toBe(-4606);

    // The reseller reference is mandatory upstream and is what ties a number
    // back to the workspace that rented it.
    Http::assertSent(fn ($request) => $request->url() === 'https://portal.apiway.com.br/api/numbers'
        && $request['partner_customer_id'] === 'tenant-'.$tenant->id
        && $request['ddd'] === '11'
        && $request['app'] === 'whatsapp');
});

test('a balance that will not cover the month is refused before anything is bought', function () {
    fakeNumbersPortal();

    $tenant = numbersTenant(1_000);
    Sanctum::actingAs($tenant->user()->first());

    $this->postJson('/api/numbers', ['ddd' => '11', 'app' => 'whatsapp'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'insufficient_credit')
        ->assertJsonPath('shortfall_cents', 3606);

    expect(VirtualNumber::count())->toBe(0);
    Http::assertNotSent(fn ($request) => $request->url() === 'https://portal.apiway.com.br/api/numbers'
        && $request->method() === 'POST');
});

test('a full upstream account gives the money straight back', function () {
    fakeNumbersPortal([
        'portal.apiway.com.br/api/numbers' => Http::response([
            'message' => 'Limite de números ativos atingido.',
            'cap' => ['used' => 3, 'max' => 3],
        ], 422),
    ]);

    $tenant = numbersTenant(10_000);
    Sanctum::actingAs($tenant->user()->first());

    // 409, not 422: nothing the customer typed is wrong, and the message must
    // not land on a field of the form they filled in correctly.
    $this->postJson('/api/numbers', ['ddd' => '11', 'app' => 'whatsapp'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'cap_reached');

    $row = VirtualNumber::first();
    expect($row->status)->toBe(VirtualNumberStatus::Failed);

    // Charged, then reversed: two rows, because both things happened.
    expect(CreditTransaction::where('reference', "numbers:buy:{$row->id}")->exists())->toBeTrue()
        ->and(CreditTransaction::where('reference', "reversal:numbers:buy:{$row->id}")->exists())->toBeTrue()
        ->and(app(\App\Services\Credits\CreditService::class)->balanceCents($tenant))->toBe(10_000);
});

test('an upstream failure that bought nothing refunds on the spot', function () {
    // A 5xx on create is ambiguous, so the account inventory is read before
    // concluding anything. Nothing matching there means nothing was bought, and
    // the customer should not be left paid-up while an hourly job catches up.
    Http::fake([
        'portal.apiway.com.br/api/numbers/catalog' => Http::response([
            'apps' => [['id' => 'whatsapp', 'label' => 'WhatsApp']],
            'regions' => ['11' => 'Sao Paulo'],
            'price_cents' => 3290,
            'currency' => 'BRL',
        ]),
        'portal.apiway.com.br/api/numbers' => Http::sequence()
            ->push(['message' => 'Contratação indisponível.'], 502)
            ->push([], 200),
    ]);

    $tenant = numbersTenant(10_000);
    Sanctum::actingAs($tenant->user()->first());

    $this->postJson('/api/numbers', ['ddd' => '11', 'app' => 'whatsapp'])
        ->assertStatus(502)
        ->assertJsonPath('code', 'purchase_reversed');

    $row = VirtualNumber::first();
    expect($row->status)->toBe(VirtualNumberStatus::Failed)
        // The row explains itself without the logs, which production loses on
        // every deploy — storage/logs lives inside the container.
        ->and($row->meta['failure']['status'] ?? null)->toBe(502)
        ->and(CreditTransaction::where('reference', "reversal:numbers:buy:{$row->id}")->exists())->toBeTrue()
        ->and(app(\App\Services\Credits\CreditService::class)->balanceCents($tenant))->toBe(10_000);
});

test('an upstream failure that did buy a number hands it over instead of refunding', function () {
    // The other half of the ambiguity: the number exists, the answer was lost.
    // Refunding here would leave the platform paying for a number nobody owns.
    Http::fake([
        'portal.apiway.com.br/api/numbers/catalog' => Http::response([
            'apps' => [['id' => 'whatsapp', 'label' => 'WhatsApp']],
            'regions' => ['11' => 'Sao Paulo'],
            'price_cents' => 3290,
            'currency' => 'BRL',
        ]),
        'portal.apiway.com.br/api/numbers' => Http::sequence()
            ->push(['message' => 'Gateway timeout'], 504)
            ->push([fakeNumberCreated(['id' => 777, 'partner_customer_id' => null])], 200),
    ]);

    $tenant = numbersTenant(10_000);
    Sanctum::actingAs($tenant->user()->first());

    $this->postJson('/api/numbers', ['ddd' => '11', 'app' => 'whatsapp'])
        ->assertCreated()
        ->assertJsonPath('data.msisdn', '5511999998888');

    $row = VirtualNumber::first();
    expect($row->status)->toBe(VirtualNumberStatus::Active)
        ->and($row->provider_number_id)->toBe(777)
        ->and($row->meta['adopted_at'] ?? null)->not->toBeNull()
        ->and(CreditTransaction::where('reference', "reversal:numbers:buy:{$row->id}")->exists())->toBeFalse();
});

test('when the inventory cannot be read either, the purchase waits instead of guessing', function () {
    Http::fake([
        'portal.apiway.com.br/api/numbers/catalog' => Http::response([
            'apps' => [['id' => 'whatsapp', 'label' => 'WhatsApp']],
            'regions' => ['11' => 'Sao Paulo'],
            'price_cents' => 3290,
            'currency' => 'BRL',
        ]),
        'portal.apiway.com.br/api/numbers' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out'),
    ]);

    $tenant = numbersTenant(10_000);
    Sanctum::actingAs($tenant->user()->first());

    $this->postJson('/api/numbers', ['ddd' => '11', 'app' => 'whatsapp'])->assertStatus(502);

    $row = VirtualNumber::first();
    expect($row->status)->toBe(VirtualNumberStatus::Pending)
        ->and($row->provider_number_id)->toBeNull()
        ->and($row->meta['unconfirmed'] ?? null)->not->toBeNull()
        // Nothing is concluded, so nothing is refunded: numbers:sync will
        // decide once the portal answers again.
        ->and(CreditTransaction::where('reference', "reversal:numbers:buy:{$row->id}")->exists())->toBeFalse();
});

test('the tenant is never shown what the platform pays for the number', function () {
    fakeNumbersPortal([
        'portal.apiway.com.br/api/numbers' => Http::response(fakeNumberCreated(), 201),
    ]);

    $tenant = numbersTenant(10_000);
    Sanctum::actingAs($tenant->user()->first());
    $this->postJson('/api/numbers', ['ddd' => '11', 'app' => 'whatsapp'])->assertCreated();

    $response = $this->getJson('/api/numbers')->assertOk();

    expect($response->json('data.0'))->not->toHaveKey('cost_cents');
    expect($this->getJson('/api/numbers/catalog')->json('data'))->not->toHaveKey('price_cents');
});

test('cancelling stops the next month and refunds nothing', function () {
    fakeNumbersPortal([
        'portal.apiway.com.br/api/numbers/128' => Http::response(['id' => 128, 'status' => 'canceled']),
        'portal.apiway.com.br/api/numbers' => Http::response(fakeNumberCreated(), 201),
    ]);

    $tenant = numbersTenant(10_000);
    Sanctum::actingAs($tenant->user()->first());
    $this->postJson('/api/numbers', ['ddd' => '11', 'app' => 'whatsapp'])->assertCreated();

    $row = VirtualNumber::first();

    $this->postJson("/api/numbers/{$row->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.cancel_reason', 'requested');

    // The month is already paid to API Way; a cancel that returned credit would
    // be the platform paying for the customer's change of mind.
    expect(CreditTransaction::where('reference', "reversal:numbers:buy:{$row->id}")->exists())->toBeFalse()
        ->and(app(\App\Services\Credits\CreditService::class)->balanceCents($tenant))->toBe(10_000 - 4606);

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://portal.apiway.com.br/api/numbers/128');
});

test('a per-app price overrides the markup, in both directions', function () {
    Setting::set(NumberPricing::KEY_APP_PRICES, json_encode(['whatsapp' => 4990]));

    expect(NumberPricing::saleCents('whatsapp', 3290))->toBe(4990)
        ->and(NumberPricing::marginCents('whatsapp', 3290))->toBe(1700)
        // An app with no override still follows the markup.
        ->and(NumberPricing::saleCents('telegram', 3290))->toBe(4606);

    // A fixed price is a price, not a floor: below cost it sells at a loss, and
    // the margin says so rather than quietly clamping.
    Setting::set(NumberPricing::KEY_APP_PRICES, json_encode(['whatsapp' => 1000]));
    expect(NumberPricing::marginCents('whatsapp', 3290))->toBe(-2290);
});

test('an unknown app or DDD is refused against the catalog, not sent upstream', function () {
    fakeNumbersPortal();

    $tenant = numbersTenant(10_000);
    Sanctum::actingAs($tenant->user()->first());

    $this->postJson('/api/numbers', ['ddd' => '99', 'app' => 'whatsapp'])->assertStatus(422);
    $this->postJson('/api/numbers', ['ddd' => '11', 'app' => 'nowhere'])->assertStatus(422);

    expect(VirtualNumber::count())->toBe(0);
    Http::assertNotSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://portal.apiway.com.br/api/numbers');
});

test('with no token stored, nothing is attempted at all', function () {
    Setting::set(ApiwayNumbersConfig::KEY_TOKEN, null);
    Http::fake();

    $tenant = numbersTenant(10_000);
    Sanctum::actingAs($tenant->user()->first());

    // 503 rather than 502: the platform has not been set up, which is a
    // different thing from API Way being down, and only one of the two is
    // fixed by waiting.
    $this->getJson('/api/numbers/catalog')
        ->assertStatus(503)
        ->assertJsonPath('code', 'unconfigured');

    $this->postJson('/api/numbers', ['ddd' => '11', 'app' => 'whatsapp'])->assertStatus(503);

    expect(VirtualNumber::count())->toBe(0);
    Http::assertNothingSent();
});

test('cancelling a purchase that never produced a number returns the money', function () {
    // The rule "cancelling refunds nothing" holds because the month is already
    // paid to API Way. A row that never got a number owes them nothing — and
    // left as an ordinary cancel it became terminal, so the hourly sync stopped
    // looking and the charge was stranded. Two production purchases were lost
    // this way before this test existed.
    Http::fake([
        'portal.apiway.com.br/api/numbers/catalog' => Http::response([
            'apps' => [['id' => 'whatsapp', 'label' => 'WhatsApp']],
            'regions' => ['11' => 'Sao Paulo'],
            'price_cents' => 3290,
            'currency' => 'BRL',
        ]),
        // Create fails ambiguously, and both inventory reads come back empty.
        'portal.apiway.com.br/api/numbers' => Http::sequence()
            ->push(['message' => 'Contratação indisponível.'], 502)
            ->push([], 200)
            ->push([], 200),
    ]);

    $tenant = numbersTenant(10_000);
    Sanctum::actingAs($tenant->user()->first());
    $this->postJson('/api/numbers', ['ddd' => '11', 'app' => 'whatsapp'])->assertStatus(502);

    // Force the row back to the state this test is about: charged, pending, no
    // provider id — which is what an unreadable inventory leaves behind.
    $row = VirtualNumber::first();
    $row->forceFill(['status' => VirtualNumberStatus::Pending])->save();
    CreditTransaction::where('reference', "reversal:numbers:buy:{$row->id}")->delete();
    app(\App\Services\Credits\CreditService::class)->adjust($tenant, -4606, 'undo test reversal');

    $this->postJson("/api/numbers/{$row->id}/cancel")->assertOk();

    expect($row->fresh()->status)->toBe(VirtualNumberStatus::Failed)
        ->and(CreditTransaction::where('reference', "reversal:numbers:buy:{$row->id}")->exists())->toBeTrue()
        ->and(app(\App\Services\Credits\CreditService::class)->balanceCents($tenant))->toBe(10_000);
});

test('cancelling an unconfirmed purchase that did produce a number cancels it upstream', function () {
    Http::fake([
        'portal.apiway.com.br/api/numbers/catalog' => Http::response([
            'apps' => [['id' => 'whatsapp', 'label' => 'WhatsApp']],
            'regions' => ['11' => 'Sao Paulo'],
            'price_cents' => 3290,
            'currency' => 'BRL',
        ]),
        'portal.apiway.com.br/api/numbers/321' => Http::response(['id' => 321, 'status' => 'canceled']),
        'portal.apiway.com.br/api/numbers' => Http::sequence()
            ->push(['message' => 'Gateway timeout'], 504)
            // Unreadable at purchase time, readable now: the row was parked.
            ->push(['message' => 'Gateway timeout'], 504)
            ->push([fakeNumberCreated(['id' => 321, 'partner_customer_id' => null])], 200),
    ]);

    $tenant = numbersTenant(10_000);
    Sanctum::actingAs($tenant->user()->first());
    $this->postJson('/api/numbers', ['ddd' => '11', 'app' => 'whatsapp'])->assertStatus(502);

    $row = VirtualNumber::first();
    expect($row->status)->toBe(VirtualNumberStatus::Pending);

    $this->postJson("/api/numbers/{$row->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    // Adopted, then cancelled for real — never refunded, because the month is
    // now genuinely owed to API Way.
    expect($row->fresh()->provider_number_id)->toBe(321)
        ->and(CreditTransaction::where('reference', "reversal:numbers:buy:{$row->id}")->exists())->toBeFalse();

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://portal.apiway.com.br/api/numbers/321');
});
