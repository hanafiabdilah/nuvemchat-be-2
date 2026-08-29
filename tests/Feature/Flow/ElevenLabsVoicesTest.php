<?php

use App\Services\AiAgentHub\ElevenLabsVoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(fn () => ElevenLabsVoices::forget());

function elevenLabsCatalogue(): array
{
    return [
        'voices' => [
            [
                'voice_id' => 'CwhRBWXzGAHq8TQ4Fs17',
                'name' => 'Roger - Laid-Back, Casual, Resonant',
                'category' => 'premade',
                'labels' => ['gender' => 'male', 'accent' => 'american', 'use_case' => 'social media'],
                // Everything a dropdown has no use for, and which the mapper
                // must not carry into the response.
                'samples' => null,
                'fine_tuning' => ['state' => ['eleven_flash_v2_5' => 'fine_tuned']],
                'sharing' => ['status' => 'enabled'],
            ],
            [
                'voice_id' => 'AZnzlk1XvdvUeBnXmlld',
                'name' => 'Domi',
                'category' => 'premade',
                'labels' => ['gender' => 'female'],
            ],
            // Neither is choosable, so neither belongs on the list.
            ['name' => 'No id at all', 'category' => 'premade'],
            ['voice_id' => 'no-name-here', 'category' => 'premade'],
        ],
    ];
}

test('the voice library is fetched without anybody\'s API key', function () {
    Http::fake(['api.elevenlabs.io/v1/voices' => Http::response(elevenLabsCatalogue())]);

    $voices = ElevenLabsVoices::all();

    expect($voices)->toHaveCount(2)
        // Sorted by name, so the dropdown reads like a list rather than like
        // whatever order the API happened to answer in.
        ->and($voices[0]['name'])->toBe('Domi')
        ->and($voices[1]['id'])->toBe('CwhRBWXzGAHq8TQ4Fs17')
        ->and($voices[1]['description'])->toBe('male · american · social media')
        ->and($voices[1])->not->toHaveKey('fine_tuning')
        ->and($voices[1])->not->toHaveKey('sharing');

    // Unauthenticated on purpose: /v1 answers with the shared library, and a
    // key would only be needed for an account's own cloned voices.
    Http::assertSent(fn ($request) => ! $request->hasHeader('xi-api-key'));
});

test('the list is cached, not fetched per flow that is opened', function () {
    Http::fake(['api.elevenlabs.io/v1/voices' => Http::response(elevenLabsCatalogue())]);

    ElevenLabsVoices::all();
    ElevenLabsVoices::all();
    ElevenLabsVoices::all();

    Http::assertSentCount(1);
});

test('a bad day at ElevenLabs leaves the flow editable', function () {
    Http::fake(['api.elevenlabs.io/v1/voices' => Http::response('nope', 503)]);

    // The dropdown is a convenience over a field that still takes a pasted id;
    // nothing here is worth throwing an editor away for.
    expect(ElevenLabsVoices::all())->toBe([]);
});

test('the endpoint serves the list to the flow builder', function () {
    Http::fake(['api.elevenlabs.io/v1/voices' => Http::response(elevenLabsCatalogue())]);

    [$user] = flowBuilderUser();

    $this->actingAs($user)
        ->getJson('/api/ai-hub/voices')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Domi')
        ->assertJsonPath('data.1.id', 'CwhRBWXzGAHq8TQ4Fs17');
});

test('it is not open to anyone who happens to be signed in', function () {
    Http::fake(['api.elevenlabs.io/v1/voices' => Http::response(elevenLabsCatalogue())]);

    [, $tenant] = flowBuilderUser();

    $stranger = App\Models\User::factory()->create(['tenant_id' => $tenant->id]);

    $this->actingAs($stranger)
        ->getJson('/api/ai-hub/voices')
        ->assertForbidden();
});

/**
 * Somebody who may edit flows, which is who this list is for.
 *
 * @return array{0: App\Models\User, 1: App\Models\Tenant}
 */
function flowBuilderUser(): array
{
    $user = App\Models\User::factory()->create();
    $tenant = App\Models\Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $permission = Spatie\Permission\Models\Permission::firstOrCreate([
        'name' => 'flows.update',
        'guard_name' => 'web',
    ]);

    $user->givePermissionTo($permission);

    return [$user->fresh(), $tenant];
}
