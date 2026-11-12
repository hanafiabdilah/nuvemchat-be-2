<?php

use App\Enums\Billing\InvoiceStatus;
use App\Enums\Billing\PaymentMethod;
use App\Enums\Numbers\VirtualNumberStatus;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VirtualNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/*
 * The Back Office lists filter, sort and page on the server. Plus three bugs
 * that only showed with real rows: the numbers and trained-agent hire lists
 * selected a tenants.name column that does not exist, and the invoices CSV
 * could not write an invoice's purpose.
 */

function listsAdmin(array $permissions): Admin
{
    $role = Role::findOrCreate('lists-'.uniqid(), 'web');
    $role->forceFill(['is_platform' => true])->save();
    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    $admin = Admin::factory()->create();
    $admin->assignRole($role);

    return $admin;
}

function listsTenant(string $name = 'Owner', ?string $email = null): Tenant
{
    $owner = User::factory()->create(['name' => $name, 'email' => $email ?? fake()->unique()->safeEmail()]);
    $tenant = Tenant::create(['user_id' => $owner->id]);
    // tenant_id is not fillable on users.
    $owner->forceFill(['tenant_id' => $tenant->id])->save();

    return $tenant->fresh();
}

function listsInvoice(Tenant $tenant, array $attributes = []): Invoice
{
    return Invoice::create(array_merge([
        'tenant_id' => $tenant->id,
        'status' => InvoiceStatus::Pending,
        'payment_method' => PaymentMethod::Pix,
        'amount_cents' => 9900,
        'currency' => 'BRL',
    ], $attributes));
}

function listIds($response): array
{
    return collect($response->json('data'))->pluck('id')->all();
}

test('users filter by owner or member and sort by name', function () {
    $tenant = listsTenant('Zoe');
    $member = User::factory()->create(['name' => 'Ana']);
    $member->forceFill(['tenant_id' => $tenant->id])->save();
    $admin = listsAdmin(['bo.users.view']);

    expect(listIds($this->actingAs($admin, 'sanctum')->getJson('/api/admin/users?role=owner')))
        ->toBe([$tenant->user_id]);
    expect(listIds($this->actingAs($admin, 'sanctum')->getJson('/api/admin/users?role=member')))
        ->toBe([$member->id]);
    expect(listIds($this->actingAs($admin, 'sanctum')->getJson('/api/admin/users?sort=name&dir=asc')))
        ->toBe([$member->id, $tenant->user_id]);
});

test('connections take several channels and statuses, and find a customer by owner', function () {
    $acme = listsTenant('Acme Store');
    $other = listsTenant('Other');
    $wa = Connection::create(['tenant_id' => $acme->id, 'name' => 'Main', 'channel' => 'whatsapp_official', 'status' => 'active']);
    $tg = Connection::create(['tenant_id' => $acme->id, 'name' => 'Bot', 'channel' => 'telegram', 'status' => 'inactive']);
    Connection::create(['tenant_id' => $other->id, 'name' => 'Insta', 'channel' => 'instagram', 'status' => 'active']);
    $admin = listsAdmin(['bo.connections.view']);

    expect(listIds($this->actingAs($admin, 'sanctum')->getJson('/api/admin/connections?channel=whatsapp_official,telegram&sort=name&dir=asc')))
        ->toBe([$tg->id, $wa->id]);
    expect(listIds($this->actingAs($admin, 'sanctum')->getJson('/api/admin/connections?search=Acme')))
        ->toEqualCanonicalizing([$wa->id, $tg->id]);
});

test('invoices find by number or payment id, take several statuses, and sort by amount', function () {
    $tenant = listsTenant();
    $small = listsInvoice($tenant, ['amount_cents' => 1000, 'payment_id' => 'pay_abc']);
    $big = listsInvoice($tenant, ['amount_cents' => 50000, 'status' => InvoiceStatus::Failed]);
    listsInvoice($tenant, ['status' => InvoiceStatus::Paid, 'paid_at' => now()]);
    $admin = listsAdmin(['bo.invoices.view']);

    expect(listIds($this->actingAs($admin, 'sanctum')->getJson('/api/admin/invoices?search=pay_abc')))->toBe([$small->id]);
    expect(listIds($this->actingAs($admin, 'sanctum')->getJson("/api/admin/invoices?search=%23{$big->id}")))->toBe([$big->id]);
    expect(listIds($this->actingAs($admin, 'sanctum')->getJson('/api/admin/invoices?status=pending,failed&sort=amount&dir=desc')))
        ->toBe([$big->id, $small->id]);
});

test('the audit trail filters by day range and lists its own actions', function () {
    $admin = listsAdmin(['bo.audit.view']);
    $old = AuditLog::create(['action' => 'plan.updated', 'description' => 'old', 'actor_name' => 'Ana']);
    $old->forceFill(['created_at' => '2026-01-05 10:00:00'])->save();
    $new = AuditLog::create(['action' => 'market.created', 'description' => 'new', 'actor_name' => 'Bia']);
    $new->forceFill(['created_at' => '2026-02-05 10:00:00'])->save();

    expect(listIds($this->actingAs($admin, 'sanctum')->getJson('/api/admin/audit-logs?from=2026-02-01&to=2026-02-28')))
        ->toBe([$new->id]);

    $this->actingAs($admin, 'sanctum')->getJson('/api/admin/audit-logs/meta')
        ->assertOk()
        ->assertJsonPath('data.actions', ['market.created', 'plan.updated'])
        ->assertJsonPath('data.actors', ['Ana', 'Bia']);
});

test('the virtual numbers list names the customer after its owner', function () {
    $tenant = listsTenant('Loja Aurora');
    VirtualNumber::create([
        'tenant_id' => $tenant->id, 'app' => 'whatsapp', 'ddd' => '11',
        'status' => VirtualNumberStatus::Active, 'cost_cents' => 3290, 'price_cents' => 4606,
    ]);

    $this->actingAs(listsAdmin(['bo.numbers.view']), 'sanctum')
        ->getJson('/api/admin/numbers')
        ->assertOk()
        ->assertJsonPath('data.0.tenant_name', 'Loja Aurora');
});

test('the invoices report writes real invoices, with their due date', function () {
    $tenant = listsTenant('Loja Aurora');
    listsInvoice($tenant, ['due_date' => '2026-05-10']);

    $response = $this->actingAs(listsAdmin(['bo.reports.export']), 'sanctum')
        ->get('/api/admin/reports/invoices')
        ->assertOk();

    $csv = $response->streamedContent();
    $lines = array_values(array_filter(explode("\n", $csv)));

    expect($lines[0])->toContain('due_date')
        ->and($lines[1])->toContain('Loja Aurora')
        ->and($lines[1])->toContain('2026-05-10');
});

test('whatsapp logs and otps cap the page size', function () {
    $admin = listsAdmin(['bo.settings.manage']);

    $this->actingAs($admin, 'sanctum')->getJson('/api/admin/whatsapp-logs?per_page=100000')
        ->assertOk()->assertJsonPath('meta.per_page', 100);
    $this->actingAs($admin, 'sanctum')->getJson('/api/admin/otps?per_page=100000')
        ->assertOk()->assertJsonPath('meta.per_page', 100);
});
