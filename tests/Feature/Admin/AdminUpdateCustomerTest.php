<?php

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** @param list<string> $permissions */
function customerEditor(array $permissions = ['bo.customers.update']): Admin
{
    $role = Role::findOrCreate('support', 'web');
    $role->forceFill(['is_platform' => true])->save();

    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    $admin = Admin::factory()->create();
    $admin->assignRole($role);

    return $admin->fresh();
}

function editableCustomer(array $owner = [], array $tenantAttributes = []): Tenant
{
    $user = User::factory()->create($owner);
    $tenant = Tenant::create(['user_id' => $user->id, ...$tenantAttributes]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $tenant->fresh();
}

/** @param array<string, mixed> $overrides */
function customerPayload(Tenant $tenant, array $overrides = []): array
{
    return [
        'name' => $tenant->user->name,
        'email' => $tenant->user->email,
        'whatsapp_number' => $tenant->user->whatsapp_number,
        'billing_name' => $tenant->billing_name,
        'billing_document_type' => $tenant->billing_document_type,
        'billing_document_number' => $tenant->billing_document_number,
        ...$overrides,
    ];
}

it('requires the update permission', function () {
    $tenant = editableCustomer();

    $this->actingAs(customerEditor(['bo.customers.view']), 'sanctum')
        ->putJson("/api/admin/customers/{$tenant->id}", customerPayload($tenant))
        ->assertForbidden();
});

it('corrects the owner and the billing identity in one save', function () {
    $tenant = editableCustomer(['email' => 'old@example.com', 'whatsapp_number' => '5511911112222']);
    $tenant->user->createToken('dashboard');

    $this->actingAs(customerEditor(), 'sanctum')
        ->putJson("/api/admin/customers/{$tenant->id}", [
            'name' => 'Ana Lima',
            'email' => 'new@example.com',
            'whatsapp_number' => '+55 (11) 93333-4444',
            'whatsapp_verified' => true,
            'billing_name' => 'Loja Aurora LTDA',
            'billing_document_type' => 'cnpj',
            'billing_document_number' => '12.345.678/0001-95',
        ])
        ->assertOk()
        ->assertJsonPath('data.owner.email', 'new@example.com')
        ->assertJsonPath('data.owner.whatsapp_number', '5511933334444')
        ->assertJsonPath('data.owner.whatsapp_verified', true)
        ->assertJsonPath('data.billing.name', 'Loja Aurora LTDA')
        ->assertJsonPath('data.billing.document_type', 'CNPJ')
        ->assertJsonPath('data.billing.document_number', '12345678000195');

    // Sessions opened under the old address do not survive it.
    expect($tenant->user->fresh()->tokens()->count())->toBe(0);

    $log = AuditLog::where('action', 'customers.update')->sole();
    expect($log->metadata['previous_email'])->toBe('old@example.com')
        ->and($log->metadata['previous_whatsapp_number'])->toBe('5511911112222')
        ->and($log->metadata['changed'])->toContain('billing_document_number');
});

it('drops the confirmation when the number changes without the operator vouching for it', function () {
    $tenant = editableCustomer(['whatsapp_number' => '5511911112222', 'whatsapp_verified_at' => now()->subMonth()]);

    $this->actingAs(customerEditor(), 'sanctum')
        ->putJson("/api/admin/customers/{$tenant->id}", customerPayload($tenant, ['whatsapp_number' => '5511955556666']))
        ->assertOk()
        ->assertJsonPath('data.owner.whatsapp_verified', false);
});

it('keeps the original confirmation date and the sessions when nothing sensitive changes', function () {
    $verifiedAt = now()->subMonth()->startOfSecond();
    $tenant = editableCustomer(['whatsapp_number' => '5511911112222', 'whatsapp_verified_at' => $verifiedAt]);
    $tenant->user->createToken('dashboard');

    $this->actingAs(customerEditor(), 'sanctum')
        ->putJson("/api/admin/customers/{$tenant->id}", customerPayload($tenant, ['name' => 'Renamed', 'whatsapp_verified' => true]))
        ->assertOk();

    $owner = $tenant->user->fresh();
    expect($owner->whatsapp_verified_at->equalTo($verifiedAt))->toBeTrue()
        ->and($owner->tokens()->count())->toBe(1);
});

it('refuses a document that is not one of the country\'s, or has the wrong length', function () {
    $tenant = editableCustomer();

    $this->actingAs(customerEditor(), 'sanctum')
        ->putJson("/api/admin/customers/{$tenant->id}", customerPayload($tenant, [
            'billing_document_type' => 'CPF',
            'billing_document_number' => '123',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['billing_document_number']);

    $this->actingAs(customerEditor(), 'sanctum')
        ->putJson("/api/admin/customers/{$tenant->id}", customerPayload($tenant, [
            'billing_document_type' => null,
            'billing_document_number' => '12345678901',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['billing_document_type']);
});

it('clears the billing document when both parts are left blank', function () {
    $tenant = editableCustomer([], [
        'billing_name' => 'Ana',
        'billing_document_type' => 'CPF',
        'billing_document_number' => '12345678901',
    ]);

    $this->actingAs(customerEditor(), 'sanctum')
        ->putJson("/api/admin/customers/{$tenant->id}", customerPayload($tenant, [
            'billing_document_type' => null,
            'billing_document_number' => null,
        ]))
        ->assertOk()
        ->assertJsonPath('data.billing.document_number', null)
        ->assertJsonPath('data.billing.name', 'Ana');
});

it('rejects an e-mail another account already uses', function () {
    $tenant = editableCustomer();
    $other = editableCustomer(['email' => 'taken@example.com']);

    $this->actingAs(customerEditor(), 'sanctum')
        ->putJson("/api/admin/customers/{$tenant->id}", customerPayload($tenant, ['email' => $other->user->email]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});
