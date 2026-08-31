<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/**
 * Somebody who may edit the workspace's AI settings.
 *
 * @return array{0: User, 1: Tenant}
 */
function vocabularyUser(string ...$permissions): array
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    foreach ($permissions ?: ['ai-agents.view', 'ai-agents.update'] as $name) {
        $user->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
    }

    return [$user->fresh(), $tenant];
}

test('an untouched workspace starts empty, alongside what the platform already covers', function () {
    config(['ai.audio.keyterms' => ['ProxyBR', 'ASN']]);

    [$user] = vocabularyUser();

    $this->actingAs($user)
        ->getJson('/api/ai-hub/vocabulary')
        ->assertOk()
        ->assertJsonPath('data.terms', [])
        // Shown read-only so nobody spends a slot re-typing a covered word.
        ->assertJsonPath('data.platform_terms', ['ProxyBR', 'ASN']);
});

test('terms are stored cleaned rather than exactly as typed', function () {
    [$user, $tenant] = vocabularyUser();

    $this->actingAs($user)
        ->putJson('/api/ai-hub/vocabulary', ['terms' => [
            ['term' => '  SOCKS5 ', 'aliases' => ['socks 5', 'socks 5']],
            ['term' => 'socks5'],
            ['term' => 'IPv6', 'aliases' => []],
        ]])
        ->assertOk()
        ->assertJsonPath('data.terms', [
            ['term' => 'SOCKS5', 'aliases' => ['socks 5']],
            ['term' => 'IPv6', 'aliases' => []],
        ]);

    expect($tenant->fresh()->audio_dictionary)->toHaveCount(2);
});

test('clearing the list empties the column rather than leaving an empty array', function () {
    [$user, $tenant] = vocabularyUser();
    $tenant->forceFill(['audio_dictionary' => [['term' => 'SOCKS5', 'aliases' => []]]])->save();

    $this->actingAs($user)
        ->putJson('/api/ai-hub/vocabulary', ['terms' => []])
        ->assertOk();

    // Null and [] behave the same on read; storing null keeps "never
    // configured" and "configured to nothing" from looking different in the DB.
    expect($tenant->fresh()->audio_dictionary)->toBeNull();
});

test('a term too short to listen for is refused with a message, not silently dropped', function () {
    [$user] = vocabularyUser();

    $this->actingAs($user)
        ->putJson('/api/ai-hub/vocabulary', ['terms' => [['term' => 'a']]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('terms.0.term');
});

test('reading it does not imply being allowed to change it', function () {
    [$user] = vocabularyUser('ai-agents.view');

    $this->actingAs($user)->getJson('/api/ai-hub/vocabulary')->assertOk();

    $this->actingAs($user)
        ->putJson('/api/ai-hub/vocabulary', ['terms' => []])
        ->assertForbidden();
});

test('it is not open to anyone who happens to be signed in', function () {
    [, $tenant] = vocabularyUser();

    $stranger = User::factory()->create(['tenant_id' => $tenant->id]);

    $this->actingAs($stranger)->getJson('/api/ai-hub/vocabulary')->assertForbidden();
});
