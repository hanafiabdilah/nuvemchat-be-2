<?php

use App\Enums\Billing\Quota;
use App\Enums\Connection\Channel;
use App\Http\Resources\Billing\SubscriptionResource;
use App\Models\Connection;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Billing\SubscriptionGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\IntegrationFixtures as Fx;

/**
 * Plans cap connections in two kinds — WhatsApp (Official and API Way) and
 * everything else — and each kind is measured on its own: a full WhatsApp
 * allowance never stops an Instagram account, nor the other way round.
 */
uses(RefreshDatabase::class);

/** @param  array<string, int|null>  $quotas */
function connectionQuotaTenant(array $quotas): Tenant
{
    $tenant = Fx::tenant();

    $plan = Plan::create([
        'name' => 'Quota',
        'slug' => 'quota-'.uniqid(),
        'price_cents' => 9900,
        'currency' => 'BRL',
        'billing_cycle' => 'monthly',
        'features' => ['chat' => true],
        'quotas' => $quotas,
    ]);

    $subscription = Subscription::create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'features_snapshot' => $plan->features,
        'quotas_snapshot' => $plan->quotas,
        'current_period_start' => now()->subDays(3),
        'current_period_end' => now()->addDays(27),
    ]);

    $tenant->update(['current_subscription_id' => $subscription->id]);

    return $tenant->fresh();
}

function connectionQuotaRow(Tenant $tenant, Channel $channel): Connection
{
    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => $channel,
        'name' => $channel->value,
    ]);
}

it('counts both WhatsApp channels, and only them, against the WhatsApp limit', function () {
    $whatsapp = Channel::valuesCountingAgainst(Quota::MaxWhatsappConnections);
    $other = Channel::valuesCountingAgainst(Quota::MaxOtherConnections);

    expect($whatsapp)->toEqualCanonicalizing(['whatsapp_official', 'whatsapp_proxyhub'])
        ->and(array_merge($whatsapp, $other))->toEqualCanonicalizing(array_column(Channel::cases(), 'value'))
        ->and(array_intersect($whatsapp, $other))->toBeEmpty();
});

it('refuses a WhatsApp number past the WhatsApp limit and still lets another channel in', function () {
    $tenant = connectionQuotaTenant(['max_whatsapp_connections' => 1, 'max_other_connections' => 5]);
    connectionQuotaRow($tenant, Channel::WhatsappOfficial);

    Sanctum::actingAs(Fx::user($tenant, ['connections.create']));

    // API Way shares the allowance with WhatsApp Official.
    $this->postJson('/api/connections', ['channel' => 'whatsapp_proxyhub', 'name' => 'Segundo número'])
        ->assertStatus(422)
        ->assertJson(['code' => 'quota_exceeded', 'quota' => 'max_whatsapp_connections']);

    $this->postJson('/api/connections', ['channel' => 'instagram', 'name' => 'Instagram'])
        ->assertCreated();

    expect(Connection::where('tenant_id', $tenant->id)->count())->toBe(2);
});

it('refuses another channel past its limit and still lets a WhatsApp number in', function () {
    $tenant = connectionQuotaTenant(['max_whatsapp_connections' => 3, 'max_other_connections' => 2]);
    connectionQuotaRow($tenant, Channel::Instagram);
    connectionQuotaRow($tenant, Channel::Email);

    Sanctum::actingAs(Fx::user($tenant, ['connections.create']));

    $this->postJson('/api/connections', ['channel' => 'telegram', 'name' => 'Bot'])
        ->assertStatus(422)
        ->assertJson(['code' => 'quota_exceeded', 'quota' => 'max_other_connections']);

    $this->postJson('/api/connections', ['channel' => 'whatsapp_official', 'name' => 'WhatsApp'])
        ->assertCreated();
});

it('counts a disconnected connection, because it still holds its place', function () {
    $tenant = connectionQuotaTenant(['max_whatsapp_connections' => 1]);
    connectionQuotaRow($tenant, Channel::WhatsappApiway)->update(['status' => 'inactive']);

    expect(app(SubscriptionGate::class)->canAddConnection($tenant, Channel::WhatsappOfficial))->toBeFalse();
});

it('applies the limit of the copy’s own kind when duplicating', function () {
    $tenant = connectionQuotaTenant(['max_whatsapp_connections' => 1, 'max_other_connections' => 5]);
    $whatsapp = connectionQuotaRow($tenant, Channel::WhatsappApiway);
    $telegram = connectionQuotaRow($tenant, Channel::Telegram);

    $user = Fx::user($tenant, ['connections.create']);
    $user->connections()->attach([$whatsapp->id, $telegram->id]);
    Sanctum::actingAs($user);

    $this->postJson("/api/connections/{$whatsapp->id}/duplicate")
        ->assertStatus(422)
        ->assertJson(['quota' => 'max_whatsapp_connections']);

    $this->postJson("/api/connections/{$telegram->id}/duplicate")
        ->assertCreated();
});

it('treats a limit the plan does not state as unlimited, as before', function () {
    $tenant = connectionQuotaTenant(['max_agents' => 2]);
    foreach (range(1, 4) as $_) {
        connectionQuotaRow($tenant, Channel::WhatsappOfficial);
        connectionQuotaRow($tenant, Channel::Instagram);
    }

    $gate = app(SubscriptionGate::class);

    expect($gate->canAddConnection($tenant, Channel::WhatsappApiway))->toBeTrue()
        ->and($gate->canAddConnection($tenant, Channel::Discord))->toBeTrue();
});

it('reports one meter per kind, plus the total for older clients', function () {
    $tenant = connectionQuotaTenant(['max_whatsapp_connections' => 2, 'max_other_connections' => 4]);
    connectionQuotaRow($tenant, Channel::WhatsappOfficial);
    connectionQuotaRow($tenant, Channel::WhatsappApiway);
    connectionQuotaRow($tenant, Channel::LiveChatWidget);

    $usage = SubscriptionResource::usageFor($tenant);

    expect($usage['whatsapp_connections'])->toBe(2)
        ->and($usage['other_connections'])->toBe(1)
        ->and($usage['connections'])->toBe(3);
});

it('splits every stored connection limit into both new limits at the old value', function () {
    $tenant = Fx::tenant();

    $plan = Plan::create([
        'name' => 'Legacy', 'slug' => 'legacy-'.uniqid(), 'price_cents' => 100, 'currency' => 'BRL', 'billing_cycle' => 'monthly',
        'quotas' => ['max_agents' => 4],
    ]);
    $unlimited = Plan::create([
        'name' => 'Open', 'slug' => 'open-'.uniqid(), 'price_cents' => 100, 'currency' => 'BRL', 'billing_cycle' => 'monthly',
        'quotas' => ['max_agents' => 4],
    ]);
    $subscription = Subscription::create([
        'tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => 'active',
        'quotas_snapshot' => ['max_agents' => 4], 'current_period_start' => now(),
    ]);

    // Rows as the previous release wrote them, straight into the columns.
    DB::table('plans')->where('id', $plan->id)->update(['quotas' => json_encode(['max_connections' => 3, 'max_agents' => 4])]);
    DB::table('plans')->where('id', $unlimited->id)->update(['quotas' => json_encode(['max_connections' => null])]);
    DB::table('subscriptions')->where('id', $subscription->id)->update([
        'quotas_snapshot' => json_encode(['max_connections' => 5, 'max_other_connections' => 9]),
    ]);
    DB::table('tenants')->where('id', $tenant->id)->update([
        'entitlement_overrides' => json_encode(['quotas' => ['max_connections' => 7], 'note' => 'Migração']),
    ]);

    (require database_path('migrations/2026_10_28_000100_split_connection_quota.php'))->up();

    expect($plan->fresh()->quotas)->toBe([
        'max_agents' => 4, 'max_whatsapp_connections' => 3, 'max_other_connections' => 3,
    ])
        ->and($unlimited->fresh()->quotas)->toBe([
            'max_whatsapp_connections' => null, 'max_other_connections' => null,
        ])
        // A value already written under a new name is kept.
        ->and($subscription->fresh()->quotas_snapshot)->toBe([
            'max_other_connections' => 9, 'max_whatsapp_connections' => 5,
        ])
        ->and($tenant->fresh()->entitlement_overrides)->toBe([
            'quotas' => ['max_whatsapp_connections' => 7, 'max_other_connections' => 7],
            'note' => 'Migração',
        ]);
});
