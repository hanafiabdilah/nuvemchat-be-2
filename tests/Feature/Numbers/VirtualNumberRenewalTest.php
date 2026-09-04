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

/**
 * The renewal, which is where this offering can quietly lose money.
 *
 * API Way has no renew endpoint: a number renews itself and bills the platform
 * on `renews_at`. So the deadline is one-sided — by then the tenant has paid for
 * the next month, or the number has to be deleted. Missing it does not lose the
 * number; it buys another month of it with the platform's money.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Setting::set(ApiwayNumbersConfig::KEY_EMAIL, 'reseller@pingly.test');
    Setting::set(ApiwayNumbersConfig::KEY_PASSWORD, 'secret');
    Setting::set(NumberPricing::KEY_MARKUP_PCT, '40');
    Setting::set(NumberPricing::KEY_APP_PRICES, json_encode([]));
    cache()->forget('apiway-numbers:token');
    cache()->forget('apiway-numbers:catalog');
});

function renewalTenant(int $balanceCents = 0): Tenant
{
    $user = User::factory()->create([
        'email' => 'ren-'.uniqid().'@example.test',
        'whatsapp_verified_at' => now(),
    ]);
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    if ($balanceCents !== 0) {
        CreditWallet::create(['tenant_id' => $tenant->id, 'balance_cents' => $balanceCents, 'currency' => 'BRL']);
    }

    return $tenant->fresh();
}

function renewalNumber(Tenant $tenant, array $overrides = []): VirtualNumber
{
    return VirtualNumber::create(array_merge([
        'tenant_id' => $tenant->id,
        'provider_number_id' => 128,
        'msisdn' => '5511999998888',
        'app' => 'whatsapp',
        'ddd' => '11',
        'status' => VirtualNumberStatus::Active,
        'cost_cents' => 3290,
        'price_cents' => 4606,
        'purchased_at' => now()->subMonth(),
        'renews_at' => now()->addDays(2),
    ], $overrides));
}

function fakeRenewalPortal(array $overrides = []): void
{
    Http::fake(array_merge([
        'portal.apiway.com.br/api/login' => Http::response(['token' => '12|abcdef']),
        'portal.apiway.com.br/api/numbers/catalog' => Http::response([
            'apps' => [['id' => 'whatsapp', 'label' => 'WhatsApp']],
            'regions' => ['11' => 'Sao Paulo'],
            'price_cents' => 3290,
            'currency' => 'BRL',
        ]),
    ], $overrides));
}

test('a renewal inside the window is charged to the balance', function () {
    fakeRenewalPortal();

    $tenant = renewalTenant(10_000);
    $number = renewalNumber($tenant);

    $this->artisan('numbers:renew')->assertSuccessful();

    $reference = "numbers:renew:{$number->id}:".$number->renews_at->toDateString();
    $debit = CreditTransaction::where('reference', $reference)->first();

    expect($debit)->not->toBeNull()
        ->and($debit->type)->toBe(CreditTransactionType::Renewal)
        ->and($debit->amount_cents)->toBe(-4606)
        ->and($number->fresh()->status)->toBe(VirtualNumberStatus::Active);
});

test('running the pass twice in the same cycle charges once', function () {
    fakeRenewalPortal();

    $tenant = renewalTenant(20_000);
    $number = renewalNumber($tenant);

    $this->artisan('numbers:renew')->assertSuccessful();
    $this->artisan('numbers:renew')->assertSuccessful();

    expect(CreditTransaction::where('type', CreditTransactionType::Renewal->value)->count())->toBe(1)
        ->and(app(\App\Services\Credits\CreditService::class)->balanceCents($tenant))->toBe(20_000 - 4606);
});

test('a renewal is re-priced from the live catalog, not from last month', function () {
    fakeRenewalPortal([
        'portal.apiway.com.br/api/numbers/catalog' => Http::response([
            'apps' => [['id' => 'whatsapp', 'label' => 'WhatsApp']],
            'regions' => ['11' => 'Sao Paulo'],
            // API Way put its price up. Charging last month's amount would move
            // the difference quietly onto the platform.
            'price_cents' => 4000,
            'currency' => 'BRL',
        ]),
    ]);

    $tenant = renewalTenant(20_000);
    $number = renewalNumber($tenant);

    $this->artisan('numbers:renew')->assertSuccessful();

    expect($number->fresh()->price_cents)->toBe(5600)
        ->and($number->fresh()->cost_cents)->toBe(4000);
});

test('a number nobody can pay for is cancelled before API Way bills us again', function () {
    fakeRenewalPortal([
        'portal.apiway.com.br/api/numbers/128' => Http::response(['id' => 128, 'status' => 'canceled']),
    ]);

    $tenant = renewalTenant(100);
    // Inside the cancel window: the renewal is hours away, not days.
    $number = renewalNumber($tenant, ['renews_at' => now()->addHours(6)]);

    $this->artisan('numbers:renew')->assertSuccessful();

    expect($number->fresh()->status)->toBe(VirtualNumberStatus::Cancelled)
        ->and($number->fresh()->cancelReason())->toBe('no_credit');

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://portal.apiway.com.br/api/numbers/128');
});

test('a short balance days ahead only warns — the number is still theirs', function () {
    fakeRenewalPortal();

    $tenant = renewalTenant(100);
    $number = renewalNumber($tenant, ['renews_at' => now()->addDays(5)]);

    $this->artisan('numbers:renew')->assertSuccessful();

    expect($number->fresh()->status)->toBe(VirtualNumberStatus::Active)
        ->and($number->fresh()->renewal_reminder_sent_at)->not->toBeNull();

    // Nothing was cancelled and nothing was charged: there are still days for
    // somebody to top up.
    Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
    expect(CreditTransaction::count())->toBe(0);
});

test('sync adopts a number that exists upstream but whose purchase never confirmed', function () {
    $tenant = renewalTenant(10_000);

    // The row a timed-out purchase leaves behind: charged, no provider id.
    $row = VirtualNumber::create([
        'tenant_id' => $tenant->id,
        'app' => 'whatsapp',
        'ddd' => '11',
        'status' => VirtualNumberStatus::Pending,
        'cost_cents' => 3290,
        'price_cents' => 4606,
        'meta' => ['unconfirmed' => ['message' => 'timed out', 'at' => now()->toISOString()]],
    ]);

    Http::fake([
        'portal.apiway.com.br/api/login' => Http::response(['token' => '12|abcdef']),
        'portal.apiway.com.br/api/numbers' => Http::response([[
            'id' => 512,
            'msisdn' => '5511777776666',
            'app' => 'whatsapp',
            'ddd' => '11',
            'region' => 'Sao Paulo',
            'status' => 'active',
            'price_cents' => 3290,
            'partner_customer_id' => 'tenant-'.$tenant->id,
            'renews_at' => now()->addDays(30)->toISOString(),
        ]]),
    ]);

    $this->artisan('numbers:sync')->assertSuccessful();

    $row->refresh();
    expect($row->provider_number_id)->toBe(512)
        ->and($row->status)->toBe(VirtualNumberStatus::Active)
        ->and($row->msisdn)->toBe('5511777776666')
        // Adopted, so the charge stands — the customer got what they paid for.
        ->and(CreditTransaction::where('reference', "reversal:numbers:buy:{$row->id}")->exists())->toBeFalse();
});

test('sync refunds a purchase that stalled with nothing to adopt', function () {
    $tenant = renewalTenant(0);

    $row = VirtualNumber::create([
        'tenant_id' => $tenant->id,
        'app' => 'whatsapp',
        'ddd' => '11',
        'status' => VirtualNumberStatus::Pending,
        'cost_cents' => 3290,
        'price_cents' => 4606,
    ]);
    // Older than the stall window, so this is no longer a purchase in flight.
    $row->forceFill(['created_at' => now()->subHour()])->save();

    // The charge that has to come back.
    app(\App\Services\Credits\CreditService::class)->adjust($tenant, 4606, 'seed');
    app(\App\Services\Credits\CreditService::class)->debit(
        $tenant,
        4606,
        CreditTransactionType::Purchase,
        "numbers:buy:{$row->id}",
        'Número virtual',
    );

    Http::fake([
        'portal.apiway.com.br/api/login' => Http::response(['token' => '12|abcdef']),
        'portal.apiway.com.br/api/numbers' => Http::response([]),
    ]);

    $this->artisan('numbers:sync')->assertSuccessful();

    expect($row->fresh()->status)->toBe(VirtualNumberStatus::Failed)
        ->and(CreditTransaction::where('reference', "reversal:numbers:buy:{$row->id}")->exists())->toBeTrue()
        ->and(app(\App\Services\Credits\CreditService::class)->balanceCents($tenant))->toBe(4606);
});

test('a number cancelled at API Way stops being live here', function () {
    $tenant = renewalTenant(10_000);
    $number = renewalNumber($tenant);

    Http::fake([
        'portal.apiway.com.br/api/login' => Http::response(['token' => '12|abcdef']),
        'portal.apiway.com.br/api/numbers' => Http::response([]),
    ]);

    $this->artisan('numbers:sync')->assertSuccessful();

    expect($number->fresh()->status)->toBe(VirtualNumberStatus::Cancelled)
        ->and($number->fresh()->cancelReason())->toBe('upstream');
});
