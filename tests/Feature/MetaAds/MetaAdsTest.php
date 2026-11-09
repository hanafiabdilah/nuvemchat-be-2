<?php

use App\Enums\Billing\BillingCycle;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\SubscriptionStatus;
use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Jobs\SendWhatsappMessageJob;
use App\Models\AdReferral;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\MetaAdAccount;
use App\Models\MetaAdInsight;
use App\Models\Plan;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Connection\Meta\FacebookConfig;
use App\Services\MetaAds\MetaAdsService;
use App\Services\Notification\NotificationConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

const META_ADS_AD = '120257190819850405';

beforeEach(function () {
    Setting::set(FacebookConfig::KEY_APP_ID, 'app-123');
    Setting::set(FacebookConfig::KEY_APP_SECRET, 'app-secret');
    Setting::set(FacebookConfig::KEY_ADS_CONFIG_ID, 'cfg-ads');
});

/** A workspace member who may manage integrations and read statistics. */
function metaAdsOwner(array $extra = []): User
{
    $user = User::factory()->create(array_merge([
        'name' => 'Ana',
        'whatsapp_number' => '5511999998888',
        'whatsapp_verified_at' => now(),
    ], $extra));
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    foreach (['integrations.view', 'integrations.manage', 'statistics.tenant.view'] as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    // The owner reaches every connection, which the WhatsApp summary counts on.
    $user->assignRole(Role::findOrCreate('owner', 'web'));

    return $user->fresh();
}

function metaAdsAccount(User $owner, array $attributes = []): MetaAdAccount
{
    return MetaAdAccount::create(array_merge([
        'tenant_id' => $owner->tenant_id,
        'account_id' => '555',
        'name' => 'Loja',
        'currency' => 'BRL',
        'timezone' => 'America/Sao_Paulo',
        'access_token' => 'sys-user-token',
        'status' => MetaAdAccount::STATUS_ACTIVE,
        'enabled' => true,
    ], $attributes));
}

/**
 * A Graph API that knows one login, one ad account and the given insights rows.
 *
 * @param  list<array<string, mixed>>  $insights
 */
function metaAdsGraph(array $insights = [], ?array $error = null): void
{
    // Http::fake keeps the first callback registered in a test, so a second
    // call only swaps what that callback answers.
    $GLOBALS['metaAdsGraph'] = ['insights' => $insights, 'error' => $error];

    Http::fake(function (HttpRequest $request) {
        ['insights' => $insights, 'error' => $error] = $GLOBALS['metaAdsGraph'];
        $url = $request->url();

        if (str_contains($url, '/oauth/access_token')) {
            return Http::response(['access_token' => 'sys-user-token', 'token_type' => 'bearer']);
        }

        if (str_contains($url, '/me/adaccounts')) {
            return Http::response(['data' => [
                ['id' => 'act_555', 'account_id' => '555', 'name' => 'Loja', 'currency' => 'BRL', 'timezone_name' => 'America/Sao_Paulo'],
            ]]);
        }

        if (str_contains($url, '/insights')) {
            return $error
                ? Http::response(['error' => $error], 400)
                : Http::response(['data' => $insights]);
        }

        if (str_contains($url, '/me')) {
            return Http::response(['id' => 'system-user-1']);
        }

        return Http::response([], 404);
    });
}

function metaAdsInsight(string $date, string $spend, string $adId = META_ADS_AD, string $name = 'Anúncio inverno'): array
{
    return [
        'ad_id' => $adId, 'ad_name' => $name, 'campaign_id' => 'c1', 'campaign_name' => 'Inverno',
        'spend' => $spend, 'impressions' => '1000', 'clicks' => '40', 'account_currency' => 'BRL',
        'date_start' => $date, 'date_stop' => $date,
    ];
}

/** A conversation that started from an ad, and optionally sold. */
function metaAdsLead(User $owner, string $phone, ?int $soldCents = null, string $adId = META_ADS_AD): Conversation
{
    $connection = Connection::firstOrCreate(
        ['tenant_id' => $owner->tenant_id, 'name' => 'WhatsApp'],
        ['channel' => Channel::WhatsappApiway, 'status' => ConnectionStatus::Active, 'credentials' => []],
    );
    $contact = Contact::create(['tenant_id' => $owner->tenant_id, 'channel' => Channel::WhatsappApiway, 'external_id' => $phone, 'name' => $phone]);
    $conversation = Conversation::create(['contact_id' => $contact->id, 'connection_id' => $connection->id, 'external_id' => $phone, 'status' => ConversationStatus::Pending]);

    AdReferral::create([
        'tenant_id' => $owner->tenant_id, 'connection_id' => $connection->id, 'conversation_id' => $conversation->id,
        'contact_id' => $contact->id, 'platform' => 'meta', 'ad_id' => $adId, 'source_type' => 'ad',
        'title' => 'Converse conosco', 'referred_at' => now(),
    ]);

    if ($soldCents !== null) {
        Sale::create([
            'tenant_id' => $owner->tenant_id, 'connection_id' => $connection->id, 'conversation_id' => $conversation->id,
            'contact_id' => $contact->id, 'source' => Sale::SOURCE_RECEIPT, 'source_id' => random_int(1, PHP_INT_MAX),
            'amount_cents' => $soldCents, 'currency' => 'BRL', 'ad_id' => $adId, 'sold_at' => now(),
        ]);
    }

    return $conversation;
}

test('connecting asks for the platform configuration first', function () {
    Setting::set(FacebookConfig::KEY_ADS_CONFIG_ID, null);
    Sanctum::actingAs(metaAdsOwner());

    $this->getJson('/api/meta-ads')->assertOk()->assertJsonPath('available', false);
    $this->postJson('/api/meta-ads/oauth')->assertStatus(422)->assertJsonPath('code', 'meta_ads_unavailable');
});

test('the Facebook login stores the shared ad accounts and reads their history', function () {
    $owner = metaAdsOwner();
    $today = Carbon::now('America/Sao_Paulo')->toDateString();
    metaAdsGraph([metaAdsInsight($today, '12.34')]);
    Sanctum::actingAs($owner);

    $url = $this->postJson('/api/meta-ads/oauth')->assertOk()->json('url');
    parse_str(parse_url($url, PHP_URL_QUERY), $query);

    expect($query['config_id'])->toBe('cfg-ads')
        ->and($query['redirect_uri'])->toBe(FacebookConfig::redirectUri())
        ->and(json_decode(base64_decode($query['state']), true)['channel'])->toBe(MetaAdsService::STATE_CHANNEL);

    $this->get('/oauth/facebook/callback?code=abc&state='.urlencode($query['state']))
        ->assertRedirectContains('/oauth/result?status=success');

    $account = MetaAdAccount::sole();

    expect($account->tenant_id)->toBe($owner->tenant_id)
        ->and($account->account_id)->toBe('555')
        ->and($account->currency)->toBe('BRL')
        ->and($account->access_token)->toBe('sys-user-token')
        ->and(DB::table('meta_ad_accounts')->value('access_token'))->not->toBe('sys-user-token')
        ->and($account->last_synced_at)->not->toBeNull()
        ->and(MetaAdInsight::sole()->spend_cents)->toBe(1234);

    // The nonce is single-use: replaying the same state stores nothing new and says so.
    $this->get('/oauth/facebook/callback?code=abc&state='.urlencode($query['state']))
        ->assertRedirectContains('status=error');

    // And the listing never carries the token.
    expect(json_encode($this->getJson('/api/meta-ads')->json()))->not->toContain('sys-user-token');
});

test('a forged state cannot attach an ad account to any workspace', function () {
    metaAdsOwner();
    metaAdsGraph();

    $state = base64_encode(json_encode(['channel' => MetaAdsService::STATE_CHANNEL, 'nonce' => 'made-up']));

    $this->get('/oauth/facebook/callback?code=abc&state='.urlencode($state))->assertRedirectContains('status=error');

    expect(MetaAdAccount::count())->toBe(0);
});

test('a sync replaces the days it reads, so a cost Meta corrected away disappears', function () {
    $owner = metaAdsOwner();
    $account = metaAdsAccount($owner);
    $today = Carbon::now('America/Sao_Paulo')->toDateString();

    metaAdsGraph([metaAdsInsight($today, '10.00'), metaAdsInsight($today, '5.50', '999', 'Outro')]);
    app(MetaAdsService::class)->sync($account, 3);
    expect(MetaAdInsight::sum('spend_cents'))->toBe(1550);

    metaAdsGraph([metaAdsInsight($today, '12.00')]);
    app(MetaAdsService::class)->sync($account->fresh(), 3);

    expect(MetaAdInsight::count())->toBe(1)
        ->and(MetaAdInsight::sole()->spend_cents)->toBe(1200);
});

test('a token Meta refuses asks for a new login and is left out of the hourly sync', function () {
    $owner = metaAdsOwner();
    $account = metaAdsAccount($owner);
    metaAdsGraph([], ['message' => 'Error validating access token', 'type' => 'OAuthException', 'code' => 190]);

    $this->artisan('meta-ads:sync')->assertSuccessful();

    $account->refresh();
    expect($account->status)->toBe(MetaAdAccount::STATUS_REAUTH)
        ->and($account->last_error)->toContain('Error validating access token');

    Http::fake();
    $this->artisan('meta-ads:sync')->assertSuccessful();
    Http::assertNothingSent();

    // The page gets the state, never Meta's sentence.
    Sanctum::actingAs($owner);
    $listed = $this->getJson('/api/meta-ads')->json('accounts.0');
    expect($listed['status'])->toBe(MetaAdAccount::STATUS_REAUTH)
        ->and($listed)->not->toHaveKey('last_error');
});

test('the sales page puts the ad cost next to what each ad brought in', function () {
    $owner = metaAdsOwner();
    $account = metaAdsAccount($owner);
    $today = Carbon::now('America/Sao_Paulo')->toDateString();

    MetaAdInsight::create(['tenant_id' => $owner->tenant_id, 'meta_ad_account_id' => $account->id, 'date' => $today, 'ad_id' => META_ADS_AD, 'ad_name' => 'Inverno', 'spend_cents' => 5000, 'currency' => 'BRL']);
    MetaAdInsight::create(['tenant_id' => $owner->tenant_id, 'meta_ad_account_id' => $account->id, 'date' => $today, 'ad_id' => '777', 'ad_name' => 'Sem resultado', 'spend_cents' => 2000, 'currency' => 'BRL']);

    metaAdsLead($owner, '5511900000001', 15000);
    metaAdsLead($owner, '5511900000002');

    Sanctum::actingAs($owner);
    $ads = $this->getJson('/api/statistics/sales?timezone=America/Sao_Paulo')->assertOk()->json('data.ads');

    expect($ads['spend'])->toMatchArray([
        'total_cents' => 7000,
        'revenue_cents' => 15000,
        'sales' => 1,
        'cost_per_lead_cents' => 3500,
        'cost_per_sale_cents' => 7000,
        'roas' => 2.14,
        'unconverted_currencies' => [],
    ])
        // The most expensive first, and an ad that spent without results is listed.
        ->and($ads['rows'][0])->toMatchArray(['ad_id' => META_ADS_AD, 'spend_cents' => 5000, 'leads' => 2, 'cost_per_lead_cents' => 2500, 'roas' => 3.0])
        ->and($ads['rows'][1])->toMatchArray(['ad_id' => '777', 'title' => 'Sem resultado', 'spend_cents' => 2000, 'leads' => 0, 'roas' => 0.0]);
});

test('without a connected account the sales page has no spend, and another currency without a rate is left out', function () {
    $owner = metaAdsOwner();
    Sanctum::actingAs($owner);

    expect($this->getJson('/api/statistics/sales')->json('data.ads.spend'))->toBeNull();

    $account = metaAdsAccount($owner, ['currency' => 'XYZ']);
    MetaAdInsight::create(['tenant_id' => $owner->tenant_id, 'meta_ad_account_id' => $account->id, 'date' => Carbon::now('America/Sao_Paulo')->toDateString(), 'ad_id' => META_ADS_AD, 'spend_cents' => 5000, 'currency' => 'XYZ']);

    $spend = $this->getJson('/api/statistics/sales?timezone=America/Sao_Paulo')->json('data.ads.spend');

    expect($spend['total_cents'])->toBe(0)
        ->and($spend['unconverted_currencies'])->toBe(['XYZ']);
});

test('switching an account off keeps it out of the numbers, and disconnecting forgets everything', function () {
    $owner = metaAdsOwner();
    $account = metaAdsAccount($owner);
    MetaAdInsight::create(['tenant_id' => $owner->tenant_id, 'meta_ad_account_id' => $account->id, 'date' => Carbon::now('America/Sao_Paulo')->toDateString(), 'ad_id' => META_ADS_AD, 'spend_cents' => 5000, 'currency' => 'BRL']);
    Sanctum::actingAs($owner);

    $this->putJson("/api/meta-ads/accounts/{$account->id}", ['enabled' => false])->assertOk()->assertJsonPath('account.enabled', false);
    expect($this->getJson('/api/statistics/sales')->json('data.ads.spend'))->toBeNull();

    // Another workspace cannot touch it.
    Sanctum::actingAs(metaAdsOwner());
    $this->putJson("/api/meta-ads/accounts/{$account->id}", ['enabled' => true])->assertNotFound();

    Sanctum::actingAs($owner);
    $this->deleteJson('/api/meta-ads')->assertOk()->assertJsonPath('removed', 1);

    expect(MetaAdAccount::count())->toBe(0)->and(MetaAdInsight::count())->toBe(0);
});

test('a plan without Meta Ads cannot reach it', function () {
    config(['services.billing.enforce' => true]);
    $owner = metaAdsOwner();

    $plan = Plan::create([
        'name' => 'Pro', 'slug' => 'pro-'.uniqid(), 'price_cents' => 9990, 'currency' => 'BRL',
        'billing_cycle' => BillingCycle::Monthly, 'is_active' => true,
        'features' => ['chat' => true, 'statistics' => true], 'quotas' => [],
    ]);
    $subscription = Subscription::create([
        'tenant_id' => $owner->tenant_id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active,
        'payment_method' => PaymentMethod::Pix, 'billing_cycle' => BillingCycle::Monthly, 'price_cents' => 9990, 'quantity' => 1,
        'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
        'quotas_snapshot' => [], 'features_snapshot' => ['chat' => true, 'statistics' => true],
    ]);
    $owner->tenant->forceFill(['current_subscription_id' => $subscription->id])->save();

    Sanctum::actingAs($owner);

    $this->getJson('/api/meta-ads')->assertForbidden();
});

test('the ads summary reports yesterday\'s spend, leads, sales and ROAS on WhatsApp', function () {
    Bus::fake([SendWhatsappMessageJob::class]);
    Setting::set(NotificationConfig::KEY_ENABLED, '1');

    $owner = metaAdsOwner(['notification_preferences' => ['whatsapp_alerts' => ['ads_summary' => 'daily']]]);
    $owner->tenant->forceFill(['timezone' => 'America/Sao_Paulo'])->save();
    $account = metaAdsAccount($owner);

    Carbon::setTestNow('2026-11-03 15:00:00'); // 12:00 in São Paulo, the day before the summary
    MetaAdInsight::create(['tenant_id' => $owner->tenant_id, 'meta_ad_account_id' => $account->id, 'date' => '2026-11-03', 'ad_id' => META_ADS_AD, 'ad_name' => 'Inverno', 'spend_cents' => 5000, 'currency' => 'BRL']);
    metaAdsLead($owner, '5511900000001', 15000);
    metaAdsLead($owner, '5511900000002');

    Carbon::setTestNow('2026-11-04 10:00:00'); // 07:00 — not yet
    $this->artisan('alerts:ads-summary')->assertSuccessful();
    expect(Bus::dispatchedAfterResponse(SendWhatsappMessageJob::class)->all())->toHaveCount(0);

    Carbon::setTestNow('2026-11-04 11:00:00'); // 08:00
    $this->artisan('alerts:ads-summary')->assertSuccessful();
    $this->artisan('alerts:ads-summary')->assertSuccessful();

    $sent = Bus::dispatchedAfterResponse(SendWhatsappMessageJob::class, fn ($job) => $job->type === 'notification:alert_ads_summary')->all();

    expect($sent)->toHaveCount(1)
        ->and($sent[0]->message)->toContain('R$ 50,00')
        ->and($sent[0]->message)->toContain('*2* leads')
        ->and($sent[0]->message)->toContain('*1* vendas (R$ 150,00)')
        ->and($sent[0]->message)->toContain('ROAS: *3,00x*')
        ->and($sent[0]->message)->toContain('Custo por lead: R$ 25,00');

    Carbon::setTestNow();
});

test('switched off on the platform, nothing about it runs and the dashboard is told so', function () {
    config(['meta_ads.enabled' => false]);
    $owner = metaAdsOwner();
    $account = metaAdsAccount($owner);
    MetaAdInsight::create(['tenant_id' => $owner->tenant_id, 'meta_ad_account_id' => $account->id, 'date' => Carbon::now('America/Sao_Paulo')->toDateString(), 'ad_id' => META_ADS_AD, 'spend_cents' => 5000, 'currency' => 'BRL']);
    Http::fake();
    Sanctum::actingAs($owner);

    $this->getJson('/api/user')->assertOk()->assertJsonPath('data.platform.meta_ads', false);
    $this->getJson('/api/meta-ads')->assertForbidden()->assertJsonPath('code', 'feature_unavailable');
    $this->postJson('/api/meta-ads/oauth')->assertForbidden();
    $this->deleteJson('/api/meta-ads')->assertForbidden();

    expect($this->getJson('/api/statistics/sales')->json('data.ads.spend'))->toBeNull();

    $this->artisan('meta-ads:sync')->assertSuccessful();
    Http::assertNothingSent();

    // Nothing stored is lost: switching it back on brings it all back.
    expect(MetaAdAccount::count())->toBe(1)->and(MetaAdInsight::count())->toBe(1);

    config(['meta_ads.enabled' => true]);
    $this->getJson('/api/user')->assertJsonPath('data.platform.meta_ads', true);
    expect($this->getJson('/api/statistics/sales?timezone=America/Sao_Paulo')->json('data.ads.spend.total_cents'))->toBe(5000);
});
