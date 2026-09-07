<?php

use App\Models\Tenant;
use App\Models\User;
use App\Services\User\AvatarStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function avatarOwner(): User
{
    $owner = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $owner->id]);
    $owner->forceFill(['tenant_id' => $tenant->id])->save();

    return $owner->fresh();
}

function avatarAgent(int $tenantId, string $email = 'agent@example.com'): User
{
    return User::factory()->create(['tenant_id' => $tenantId, 'email' => $email]);
}

/** Give the acting user exactly one permission, on a role of their own. */
function avatarGrant(User $user, string ...$permissions): User
{
    $role = Role::findOrCreate('supervisor-' . $user->id, 'web');
    $role->givePermissionTo($permissions);
    $user->assignRole($role);

    return $user->fresh();
}

/** A real PNG, so both the `image` rule and the resize path have something to chew. */
function avatarFile(int $side = 900): UploadedFile
{
    return UploadedFile::fake()->image('face.png', $side, $side);
}

beforeEach(function () {
    Storage::fake('local');

    foreach (['agents.view', 'agents.update', 'agents.update-avatar', 'agents.delete'] as $name) {
        Permission::findOrCreate($name, 'web');
    }
});

test('a manager with the avatar permission can set an agent photo', function () {
    $owner = avatarOwner();
    $agent = avatarAgent($owner->tenant_id);

    $this->actingAs(avatarGrant($owner, 'agents.update-avatar'))
        ->post("/api/agents/{$agent->id}/avatar", ['avatar' => avatarFile()])
        ->assertOk()
        ->assertJsonPath('data.email', 'agent@example.com');

    $stored = $agent->fresh()->avatar_path;

    expect($stored)->not->toBeNull()
        ->and(Storage::disk('local')->exists($stored))->toBeTrue();
});

/**
 * The whole reason the permission stands on its own: whoever may put a face on
 * the roster must not thereby be able to change how a colleague signs in.
 */
test('the avatar permission does not carry the ability to edit the account', function () {
    $owner = avatarOwner();
    $agent = avatarAgent($owner->tenant_id);

    $this->actingAs(avatarGrant($owner, 'agents.update-avatar'))
        ->putJson("/api/agents/{$agent->id}", [
            'name' => 'Renamed',
            'email' => 'stolen@example.com',
        ])
        ->assertForbidden();

    expect($agent->fresh()->email)->toBe('agent@example.com');
});

test('an agent without the permission cannot set anyone elses photo', function () {
    $owner = avatarOwner();
    $agent = avatarAgent($owner->tenant_id);
    $other = avatarAgent($owner->tenant_id, 'other@example.com');

    $this->actingAs($agent)
        ->post("/api/agents/{$other->id}/avatar", ['avatar' => avatarFile()])
        ->assertForbidden();

    expect($other->fresh()->avatar_path)->toBeNull();
});

test('a photo cannot be set on an agent belonging to another tenant', function () {
    $owner = avatarOwner();
    $stranger = avatarOwner();
    $victim = avatarAgent($stranger->tenant_id, 'victim@example.com');

    $this->actingAs(avatarGrant($owner, 'agents.update-avatar'))
        ->post("/api/agents/{$victim->id}/avatar", ['avatar' => avatarFile()])
        ->assertNotFound();

    expect($victim->fresh()->avatar_path)->toBeNull();
});

test('replacing a photo deletes the file it replaced', function () {
    $owner = avatarOwner();
    $agent = avatarAgent($owner->tenant_id);

    $storage = app(AvatarStorage::class);
    $storage->store($agent, avatarFile());
    $first = $agent->fresh()->avatar_path;

    $storage->store($agent->fresh(), avatarFile());
    $second = $agent->fresh()->avatar_path;

    expect($second)->not->toBe($first)
        ->and(Storage::disk('local')->exists($first))->toBeFalse()
        ->and(Storage::disk('local')->exists($second))->toBeTrue();
});

test('deleting an agent takes their photo with it', function () {
    $owner = avatarOwner();
    $agent = avatarAgent($owner->tenant_id);

    app(AvatarStorage::class)->store($agent, avatarFile());
    $path = $agent->fresh()->avatar_path;

    $this->actingAs(avatarGrant($owner, 'agents.delete'))
        ->deleteJson("/api/agents/{$agent->id}")
        ->assertOk();

    expect(Storage::disk('local')->exists($path))->toBeFalse();
});

test('a user sets and clears their own photo without holding any permission', function () {
    $owner = avatarOwner();
    $agent = avatarAgent($owner->tenant_id);

    $response = $this->actingAs($agent)
        ->post('/api/user/avatar', ['avatar' => avatarFile()])
        ->assertOk();

    expect($response->json('avatar'))->toBeString()
        ->and($agent->fresh()->avatar_path)->not->toBeNull();

    $this->actingAs($agent->fresh())
        ->deleteJson('/api/user/avatar')
        ->assertOk()
        ->assertJsonPath('avatar', null);

    expect($agent->fresh()->avatar_path)->toBeNull();
});

test('the photo reaches the payload the dashboard boots from', function () {
    $owner = avatarOwner();

    app(AvatarStorage::class)->store($owner, avatarFile());

    $avatar = $this->actingAs($owner->fresh())
        ->getJson('/api/user')
        ->assertOk()
        ->json('data.avatar');

    expect($avatar)->toBeString()->toContain('avatars/');
});

test('an account with no photo reports null rather than a dead link', function () {
    $owner = avatarOwner();

    $avatar = $this->actingAs($owner)
        ->getJson('/api/user')
        ->assertOk()
        ->json('data.avatar');

    expect($avatar)->toBeNull();
});

/**
 * The cap is the reason the resize exists at all: without one, a camera photo
 * is re-served on every row of the agents page, on every visit.
 */
test('an oversized image is refused', function () {
    $owner = avatarOwner();
    $agent = avatarAgent($owner->tenant_id);

    $this->actingAs($agent)
        ->postJson('/api/user/avatar', [
            'avatar' => UploadedFile::fake()->create('huge.png', AvatarStorage::MAX_KILOBYTES + 512, 'image/png'),
        ])
        ->assertStatus(422);

    expect($agent->fresh()->avatar_path)->toBeNull();
});

test('an svg is refused — it is a document that can carry script', function () {
    $owner = avatarOwner();
    $agent = avatarAgent($owner->tenant_id);

    $this->actingAs($agent)
        ->postJson('/api/user/avatar', [
            'avatar' => UploadedFile::fake()->create('payload.svg', 4, 'image/svg+xml'),
        ])
        ->assertStatus(422);

    expect($agent->fresh()->avatar_path)->toBeNull();
});

test('a large upload is stored scaled down', function () {
    $owner = avatarOwner();

    app(AvatarStorage::class)->store($owner, avatarFile(1600));

    $path = $owner->fresh()->avatar_path;
    $dimensions = getimagesizefromstring(Storage::disk('local')->get($path));

    expect(max($dimensions[0], $dimensions[1]))->toBe(AvatarStorage::MAX_DIMENSION);
});
