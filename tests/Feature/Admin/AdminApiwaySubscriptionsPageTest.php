<?php

use App\Enums\Apiway\ApiwaySubscriptionSource;
use App\Enums\Apiway\ApiwaySubscriptionStatus;
use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Jobs\ProvisionApiwaySubscription;
use App\Models\Admin;
use App\Models\ApiwayInstance;
use App\Models\ApiwaySubscription;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The Back Office API Way Instances page: one row per instance across every
 * workspace, the counts an operator needs on top, and the troubleshooting
 * actions. No money anywhere — instances are free since Nov 2026.
 */
uses(RefreshDatabase::class);

function apwPageAdmin(): Admin
{
    $role = Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();
    $role->givePermissionTo(Permission::findOrCreate('bo.subscriptions.manage', 'web'));

    $admin = Admin::factory()->create();
    $admin->assignRole($role);

    return $admin;
}

function apwPageTenant(string $name): Tenant
{
    $user = User::factory()->create(['name' => $name, 'email' => 'apw-'.uniqid().'@example.test']);
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $tenant->fresh();
}

function apwPageRow(Tenant $tenant, array $attributes = []): ApiwaySubscription
{
    return ApiwaySubscription::create(array_merge([
        'tenant_id' => $tenant->id,
        'external_ref' => 'pingly-apw-page-'.uniqid(),
        'source' => ApiwaySubscriptionSource::Unit,
        'cycle' => 'mensal',
        'quantity' => 1,
        'unit_price_cents' => 4990,
        'total_price_cents' => 4990,
        'currency' => 'BRL',
        'location_code' => 'br',
        'status' => ApiwaySubscriptionStatus::Active,
        'expires_at' => now()->addDays(20),
    ], $attributes));
}

function apwPageInstance(ApiwaySubscription $row, ?Connection $connection = null): ApiwayInstance
{
    return ApiwayInstance::create([
        'tenant_id' => $row->tenant_id,
        'apiway_subscription_id' => $row->id,
        'provider_instance_id' => 'uuid-'.uniqid(),
        'name' => 'Instancia',
        'status' => 'conectado',
        'connection_id' => $connection?->id,
    ]);
}

function apwPageConnection(Tenant $tenant, string $name = 'Vendas'): Connection
{
    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappApiway,
        'name' => $name,
        'status' => ConnectionStatus::Active,
        'credentials' => ['phone_number' => '5511999999999'],
    ]);
}

test('the summary counts instances — linked, spare, lapsing — and carries no money', function () {
    $tenant = apwPageTenant('Loja Aurora');
    apwPageInstance(apwPageRow($tenant), apwPageConnection($tenant));
    // Spare and inside the week: it will not renew, so it is lapsing.
    apwPageInstance(apwPageRow($tenant, ['expires_at' => now()->addDays(3)]));
    apwPageRow($tenant, ['status' => ApiwaySubscriptionStatus::Cancelled]);

    $data = $this->actingAs(apwPageAdmin(), 'sanctum')
        ->getJson('/api/admin/apiway/subscriptions/summary')
        ->assertOk()
        ->json('data');

    expect($data['live_instances'])->toBe(2)
        ->and($data['linked_instances'])->toBe(1)
        ->and($data['spare_instances'])->toBe(1)
        ->and($data['lapsing_count'])->toBe(1)
        ->and($data['tenant_count'])->toBe(1)
        ->and($data)->not->toHaveKey('monthly_revenue_cents');
});

test('each row is one instance with the connection it serves, and no prices', function () {
    $aurora = apwPageTenant('Loja Aurora');
    $other = apwPageTenant('Outra Empresa');
    $row = apwPageRow($aurora);
    apwPageInstance($row, apwPageConnection($aurora, 'Vendas'));
    apwPageRow($other);

    $rows = $this->actingAs(apwPageAdmin(), 'sanctum')
        ->getJson('/api/admin/apiway/subscriptions?search=Aurora')
        ->assertOk()
        ->json('data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['tenant_name'])->toBe('Loja Aurora')
        ->and($rows[0]['connection']['name'])->toBe('Vendas')
        ->and($rows[0]['connection']['phone_number'])->toBe('5511999999999')
        ->and($rows[0]['instance']['provider_instance_id'])->not->toBeNull()
        ->and($rows[0])->not->toHaveKey('total_price_cents')
        ->and($rows[0])->not->toHaveKey('unit_price_cents');
});

test('the list narrows to spare instances and to what lapses without renewing', function () {
    $tenant = apwPageTenant('Loja Aurora');
    $linked = apwPageRow($tenant, ['expires_at' => now()->addDays(2)]);
    apwPageInstance($linked, apwPageConnection($tenant));
    $spare = apwPageRow($tenant, ['expires_at' => now()->addDays(2)]);
    apwPageInstance($spare);

    $admin = apwPageAdmin();

    $rows = $this->actingAs($admin, 'sanctum')
        ->getJson('/api/admin/apiway/subscriptions?usage=spare')
        ->assertOk()
        ->json('data');
    expect(collect($rows)->pluck('id')->all())->toBe([$spare->id]);

    // The linked one has no usable plan behind it in this test, so both lapse.
    $rows = $this->actingAs($admin, 'sanctum')
        ->getJson('/api/admin/apiway/subscriptions?lapsing=1')
        ->assertOk()
        ->json('data');
    expect(collect($rows)->pluck('id')->sort()->values()->all())->toBe([$linked->id, $spare->id]);
});

test('retrying a failed free instance queues it again and is audited', function () {
    Bus::fake();
    $tenant = apwPageTenant('Loja Aurora');
    $row = apwPageRow($tenant, [
        'source' => ApiwaySubscriptionSource::PlanIncluded,
        'status' => ApiwaySubscriptionStatus::Failed,
        'meta' => ['failure' => ['code' => 'no_enabled_subnet_capacity', 'message' => 'x']],
    ]);

    $this->actingAs(apwPageAdmin(), 'sanctum')
        ->postJson("/api/admin/apiway/subscriptions/{$row->id}/retry")
        ->assertOk()
        ->assertJsonPath('data.status', 'provisioning')
        ->assertJsonPath('data.failure', null);

    Bus::assertDispatched(ProvisionApiwaySubscription::class);
    expect(AuditLog::where('action', 'apiway.instance.retried')->count())->toBe(1);
});

test('a row that exists at ProxyBR cannot be retried', function () {
    $tenant = apwPageTenant('Loja Aurora');
    $row = apwPageRow($tenant, ['provider_subscription_id' => 42]);

    $this->actingAs(apwPageAdmin(), 'sanctum')
        ->postJson("/api/admin/apiway/subscriptions/{$row->id}/retry")
        ->assertStatus(422);
});
