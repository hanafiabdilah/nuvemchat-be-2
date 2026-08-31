<?php

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Back Office admins are their own table.
 *
 * The visible reason is the email: an operator's address used to be spent on
 * the Back Office and unavailable to the company they work for, and the
 * rejection ("email already taken") named a row neither of them could see. The
 * structural reason is that the two are different populations — an admin has
 * no tenant, no connections and no billing — and a shared table made "is this
 * a customer?" a nullable column rather than a type.
 */
uses(RefreshDatabase::class);

function adminsPageAdmin(): Admin
{
    $role = Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();
    $role->givePermissionTo(Permission::findOrCreate('bo.admins.manage', 'web'));

    $admin = Admin::factory()->create();
    $admin->assignRole($role);

    return $admin;
}

function adminsPageTenantUser(array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->update(['tenant_id' => $tenant->id]);

    return $user->fresh();
}

test('an admin can be created with an email a customer already uses', function () {
    adminsPageTenantUser(['email' => 'contato@empresa.com.br']);

    $this->actingAs(adminsPageAdmin(), 'sanctum')
        ->postJson('/api/admin/admins', [
            'name' => 'Operator',
            'email' => 'contato@empresa.com.br',
            'password' => 'supersecret',
            'role' => 'super-admin',
        ])
        ->assertCreated();

    $this->assertDatabaseHas('admins', ['email' => 'contato@empresa.com.br']);
    // And the customer still owns theirs.
    $this->assertDatabaseHas('users', ['email' => 'contato@empresa.com.br']);
});

test('a customer can still register with an email an admin uses', function () {
    Role::findOrCreate('owner', 'web');
    Admin::factory()->create(['email' => 'ops@pingly.com.br']);

    $this->postJson('/api/auth/register', [
        'name' => 'Nova Empresa',
        'email' => 'ops@pingly.com.br',
        'password' => 'supersecret',
        'password_confirmation' => 'supersecret',
        'whatsapp_number' => '+5511999999999',
    ])->assertCreated();

    $this->assertDatabaseHas('users', ['email' => 'ops@pingly.com.br']);
});

test('two admins still cannot share an email, and the 422 names the field', function () {
    Admin::factory()->create(['email' => 'ops@pingly.com.br']);

    $this->actingAs(adminsPageAdmin(), 'sanctum')
        ->postJson('/api/admin/admins', [
            'name' => 'Second',
            'email' => 'ops@pingly.com.br',
            'password' => 'supersecret',
            'role' => 'super-admin',
        ])
        ->assertStatus(422)
        // Both halves matter to the frontend: `errors.email` puts the message
        // under the input, `message` fills the toast.
        ->assertJsonValidationErrors('email')
        ->assertJsonPath('message', fn ($message) => is_string($message) && $message !== '');
});

test('a tenant user cannot sign in to the Back Office, even with the right password', function () {
    $role = Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();

    // Same address, same password, different table: the customer's credentials
    // are not a candidate on this surface at all.
    adminsPageTenantUser(['email' => 'shared@empresa.com.br', 'password' => 'password']);

    $this->postJson('/api/admin/auth/login', [
        'email' => 'shared@empresa.com.br',
        'password' => 'password',
    ])->assertStatus(401);
});

test('an admin signs in as themselves when a customer shares the address', function () {
    $role = Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();

    adminsPageTenantUser(['email' => 'shared@empresa.com.br', 'password' => 'password']);
    $admin = Admin::factory()->create(['email' => 'shared@empresa.com.br']);
    $admin->assignRole($role);

    $this->postJson('/api/admin/auth/login', [
        'email' => 'shared@empresa.com.br',
        'password' => 'password',
    ])
        ->assertOk()
        ->assertJsonPath('user.id', $admin->id)
        ->assertJsonPath('user.roles.0', 'super-admin');
});

test('a tenant user token is refused on the Back Office surface', function () {
    $this->actingAs(adminsPageTenantUser(), 'sanctum')
        ->getJson('/api/admin/admins')
        ->assertForbidden();
});

test('an admin with no platform role is refused rather than half-admitted', function () {
    $this->actingAs(Admin::factory()->create(), 'sanctum')
        ->getJson('/api/admin/auth/me')
        ->assertForbidden();
});

test('the admin list holds only admins, never tenant users', function () {
    $admin = adminsPageAdmin();
    adminsPageTenantUser();

    $res = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/admins')->assertOk();

    expect($res->json('data'))->toHaveCount(1);
    expect($res->json('data.0.id'))->toBe($admin->id);
    expect($res->json('data.0.is_self'))->toBeTrue();
});

test('an audit row records which table its actor came from', function () {
    $admin = adminsPageAdmin();

    $this->actingAs($admin, 'sanctum')->postJson('/api/admin/admins', [
        'name' => 'Operator',
        'email' => 'operator@pingly.com.br',
        'password' => 'supersecret',
        'role' => 'super-admin',
    ])->assertCreated();

    $log = AuditLog::where('action', 'admin.create')->firstOrFail();

    expect($log->actor_id)->toBe($admin->id);
    expect($log->actor_type)->toBe(Admin::class);
    // The relation has to resolve to the admin, not to whichever customer
    // happens to hold that id in `users`.
    expect($log->actor?->is($admin))->toBeTrue();
});

test('an admin can take an email a customer holds when editing their own profile', function () {
    adminsPageTenantUser(['email' => 'contato@empresa.com.br']);

    $this->actingAs(adminsPageAdmin(), 'sanctum')
        ->putJson('/api/admin/account', [
            'name' => 'Operator',
            'email' => 'contato@empresa.com.br',
        ])
        ->assertOk()
        ->assertJsonPath('data.email', 'contato@empresa.com.br');
});

test('a platform role counts the admins holding it, not the users table', function () {
    // Spatie's Role::users() resolves the model from the auth provider for the
    // guard — which is User. Admins are not there any more, so a role that has
    // been handed out would report zero members and look safe to delete.
    $role = Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();
    $role->givePermissionTo(Permission::findOrCreate('bo.roles.manage', 'web'));

    $admin = Admin::factory()->create();
    $admin->assignRole($role);

    $support = Role::create(['name' => 'support', 'guard_name' => 'web', 'is_platform' => true]);
    Admin::factory()->create()->assignRole($support);
    Admin::factory()->create()->assignRole($support);

    $res = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/roles')->assertOk();

    $counts = collect($res->json('data'))->pluck('users_count', 'name');

    expect($counts['super-admin'])->toBe(1);
    expect($counts['support'])->toBe(2);
});

test('a role still assigned to an admin cannot be deleted', function () {
    $role = Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();
    $role->givePermissionTo(Permission::findOrCreate('bo.roles.manage', 'web'));

    $admin = Admin::factory()->create();
    $admin->assignRole($role);

    $support = Role::create(['name' => 'support', 'guard_name' => 'web', 'is_platform' => true]);
    Admin::factory()->create()->assignRole($support);

    $this->actingAs($admin, 'sanctum')
        ->deleteJson("/api/admin/roles/{$support->id}")
        ->assertStatus(422);

    $this->assertDatabaseHas('roles', ['id' => $support->id]);
});

test('impersonation cannot target an admin id', function () {
    $role = Role::findOrCreate('super-admin', 'web');
    $role->forceFill(['is_platform' => true])->save();
    $role->givePermissionTo(Permission::findOrCreate('bo.impersonate', 'web'));

    $actor = Admin::factory()->create();
    $actor->assignRole($role);

    $target = Admin::factory()->create();

    $this->actingAs($actor, 'sanctum')
        ->postJson('/api/admin/impersonate', ['user_id' => $target->id])
        ->assertStatus(422);
});
