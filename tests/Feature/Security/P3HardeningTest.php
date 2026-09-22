<?php

use App\Models\AuditLog;
use App\Models\GalleryAsset;
use App\Services\Media\UploadPolicy;
use App\Support\LogFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Support\GalleryFixtures;

/**
 * The fourth tier of the security review: the findings that are real but small,
 * or that only bite under conditions nobody arranges on purpose.
 */
uses(RefreshDatabase::class);

// ── The name the browser sent is not the file's type ──

it('stores a file under the extension its bytes say, not the one it claimed', function () {
    Storage::fake('local');

    $tenant = GalleryFixtures::tenant(planGb: 1);
    Sanctum::actingAs($tenant->user);

    // A real PNG wearing a .pdf name. Taking the name at its word wrote it to
    // disk as `.pdf`, signed `.pdf` into the public filename, and OutboundMedia
    // then announced application/pdf to WhatsApp for a picture.
    $png = UploadedFile::fake()->image('catalogo.png')->size(20);
    $disguised = new UploadedFile(
        $png->getRealPath(),
        'catalogo.pdf',
        'application/pdf',
        test: true,
    );

    $this->post('/api/gallery', ['file' => $disguised])->assertCreated();

    $asset = GalleryAsset::first();

    expect($asset->path)->toEndWith('.png')
        ->and($asset->public_filename)->toEndWith('.png');
});

it('keeps the spelling the uploader chose when it agrees with the bytes', function () {
    // `.m4a` and `.mp4` are one MIME type with two spellings this platform
    // genuinely distinguishes, so the client's is kept when it is honest.
    // Overwriting it would change working audio behaviour to fix nothing.
    $jpeg = UploadedFile::fake()->image('foto.jpg');

    expect(UploadPolicy::storedExtension($jpeg))->toBe('jpg');
});

// ── Statistics dates ──

it('refuses a date expression where a calendar day belongs', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    Permission::findOrCreate('statistics.tenant.view', 'web');
    $tenant->user->givePermissionTo('statistics.tenant.view');
    Sanctum::actingAs($tenant->user);

    // Laravel's `date` rule is strtotime(), so this used to reach Carbon::parse
    // as an arbitrary expression rather than a day.
    $this->getJson('/api/statistics/overview?from=-500+years&to=now')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['from']);
});

// ── Reading logs ──

it('reads only the end of a log file and says so', function () {
    $path = storage_path('logs/p3-hardening-test.log');

    // One entry per line, far more than the window below.
    $entry = "[2026-09-22 10:00:00] testing.ERROR: line %d\n";
    $handle = fopen($path, 'wb');
    for ($i = 0; $i < 4000; $i++) {
        fwrite($handle, sprintf($entry, $i));
    }
    fclose($handle);

    try {
        $read = LogFile::read($path, maxBytes: 4096);

        expect($read['truncated'])->toBeTrue()
            ->and($read['scanned'])->toBeLessThanOrEqual(4096)
            ->and($read['size'])->toBeGreaterThan(4096)
            // The newest entry is present; the oldest is outside the window.
            ->and(collect($read['entries'])->last()['message'])->toBe('line 3999')
            ->and(collect($read['entries'])->pluck('message'))->not->toContain('line 0');

        // The whole file still parses when it fits.
        expect(LogFile::read($path)['truncated'])->toBeFalse();
    } finally {
        @unlink($path);
    }
});

// ── Who was given what ──

it('records a role being created with the permissions it carries', function () {
    $tenant = GalleryFixtures::tenant();
    Permission::findOrCreate('roles.create', 'web');
    Permission::findOrCreate('contacts.update', 'web');
    $tenant->user->givePermissionTo(['roles.create', 'contacts.update']);
    Sanctum::actingAs($tenant->user);

    $this->postJson('/api/roles', [
        'name' => 'Supervisor',
        'permissions' => ['contacts.update'],
    ])->assertCreated();

    $log = AuditLog::where('action', 'role.created')->first();

    expect($log)->not->toBeNull()
        ->and($log->actor_type)->toBe(\App\Models\User::class)
        ->and($log->metadata['tenant_id'])->toBe($tenant->id)
        ->and($log->metadata['permissions'])->toBe(['contacts.update']);
});

it('records what an agent was holding before their permissions changed', function () {
    $tenant = GalleryFixtures::tenant();
    Permission::findOrCreate('agents.assign-permissions', 'web');
    Permission::findOrCreate('contacts.update', 'web');
    Permission::findOrCreate('contacts.view', 'web');
    // The permissions themselves rather than the owner role: assertMayGrant
    // only asks whether the caller holds what they are handing out.
    $tenant->user->givePermissionTo(['agents.assign-permissions', 'contacts.update', 'contacts.view']);
    Sanctum::actingAs($tenant->user);

    $agent = \App\Models\User::factory()->create(['tenant_id' => $tenant->id]);
    $agent->givePermissionTo('contacts.view');

    $this->postJson("/api/agents/{$agent->id}/assign-permissions", [
        'permissions' => ['contacts.update'],
    ])->assertOk();

    $log = AuditLog::where('action', 'agent.permissions_assigned')->first();

    expect($log)->not->toBeNull()
        ->and($log->metadata['user_id'])->toBe($agent->id)
        ->and($log->metadata['previous_permissions'])->toBe(['contacts.view'])
        ->and($log->metadata['permissions'])->toBe(['contacts.update']);
});
