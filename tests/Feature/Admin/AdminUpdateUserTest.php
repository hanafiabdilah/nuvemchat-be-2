<?php

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** @param list<string> $permissions */
function userEditor(array $permissions = ['bo.users.manage']): Admin
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

function editableCustomerUser(array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    return $user->fresh();
}

it('requires the manage permission', function () {
    $user = editableCustomerUser();

    $this->actingAs(userEditor(['bo.users.view']), 'sanctum')
        ->putJson("/api/admin/users/{$user->id}", ['name' => 'X', 'email' => 'x@example.com'])
        ->assertForbidden();
});

it('updates the name and e-mail and records who did it', function () {
    $user = editableCustomerUser(['email' => 'old@example.com']);
    $user->createToken('dashboard');

    $this->actingAs(userEditor(), 'sanctum')
        ->putJson("/api/admin/users/{$user->id}", ['name' => 'Ana Lima', 'email' => 'new@example.com'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Ana Lima')
        ->assertJsonPath('data.email', 'new@example.com')
        ->assertJsonPath('data.tenant.id', $user->tenant_id);

    $user->refresh();
    expect($user->email)->toBe('new@example.com')
        // A session opened under the old address does not survive the change.
        ->and($user->tokens()->count())->toBe(0);

    $log = AuditLog::where('action', 'users.update')->sole();
    expect($log->metadata['changed'])->toEqualCanonicalizing(['name', 'email'])
        ->and($log->metadata['previous_email'])->toBe('old@example.com');
});

it('leaves the password and sessions alone when only the name changes', function () {
    $user = editableCustomerUser(['password' => 'the-original-password']);
    $user->createToken('dashboard');

    $this->actingAs(userEditor(), 'sanctum')
        ->putJson("/api/admin/users/{$user->id}", ['name' => 'Renamed', 'email' => $user->email, 'password' => ''])
        ->assertOk();

    $user->refresh();
    expect(Hash::check('the-original-password', $user->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(1);
});

it('sets a new password, ends every session and never logs the password', function () {
    $user = editableCustomerUser();
    $user->createToken('dashboard');

    $this->actingAs(userEditor(), 'sanctum')
        ->putJson("/api/admin/users/{$user->id}", [
            'name' => $user->name,
            'email' => $user->email,
            'password' => 'a-brand-new-password',
        ])
        ->assertOk();

    $user->refresh();
    expect(Hash::check('a-brand-new-password', $user->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(0);

    $log = AuditLog::where('action', 'users.update')->sole();
    expect($log->metadata['changed'])->toBe(['password'])
        ->and(json_encode($log->metadata))->not->toContain('a-brand-new-password');
});

it('rejects an e-mail another user already has and a weak password', function () {
    $user = editableCustomerUser();
    $other = editableCustomerUser(['email' => 'taken@example.com']);

    $this->actingAs(userEditor(), 'sanctum')
        ->putJson("/api/admin/users/{$user->id}", ['name' => 'A', 'email' => $other->email, 'password' => 'short'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email', 'password']);
});

it('does not edit an account that belongs to no workspace', function () {
    $orphan = User::factory()->create();

    $this->actingAs(userEditor(), 'sanctum')
        ->putJson("/api/admin/users/{$orphan->id}", ['name' => 'A', 'email' => 'a@example.com'])
        ->assertNotFound();
});
