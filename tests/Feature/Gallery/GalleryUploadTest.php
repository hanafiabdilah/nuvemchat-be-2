<?php

use App\Models\GalleryAsset;
use App\Services\Gallery\GalleryStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\GalleryFixtures;

/**
 * Putting files in the library: what counts against the space, what does not,
 * and what happens at the line.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

it('stores a file and counts it against the workspace quota', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    Sanctum::actingAs($tenant->user);

    $response = $this->post('/api/gallery', [
        'file' => UploadedFile::fake()->image('catalogo.jpg')->size(120),
    ]);

    $response->assertCreated();

    $asset = GalleryAsset::first();

    expect($asset)->not->toBeNull()
        ->and($asset->tenant_id)->toBe($tenant->id)
        ->and($asset->type->value)->toBe('image')
        ->and($asset->uploaded_by_user_id)->toBe($tenant->user->id);

    Storage::disk('local')->assertExists($asset->path);

    expect(app(GalleryStorage::class)->usedBytes($tenant))->toBe($asset->size_bytes);
});

it('returns the file it already has instead of storing the same bytes twice', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    Sanctum::actingAs($tenant->user);

    $file = UploadedFile::fake()->image('catalogo.jpg')->size(120);

    $first = $this->post('/api/gallery', ['file' => $file]);
    $first->assertCreated();

    // The obvious way to use a gallery — drag the same folder in again next
    // month — must not double the bill.
    $second = $this->post('/api/gallery', ['file' => $file]);

    $second->assertOk()
        ->assertJsonPath('duplicate', true)
        ->assertJsonPath('data.id', $first->json('data.id'));

    expect(GalleryAsset::count())->toBe(1);
});

it('refuses an upload that would not fit, and says how much is missing', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    // Almost the whole gigabyte is already in use.
    GalleryFixtures::asset($tenant, GalleryStorage::BYTES_PER_GB - 50_000);

    Sanctum::actingAs($tenant->user);

    $response = $this->post('/api/gallery', [
        'file' => UploadedFile::fake()->image('grande.jpg')->size(200), // ~200 KB
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('code', 'gallery_quota_exceeded');

    // The numbers are the point of the error: without them the screen can only
    // say "full" and leave the customer to guess between deleting and renting.
    expect($response->json('shortfall_bytes'))->toBeGreaterThan(0)
        ->and($response->json('limit_bytes'))->toBe(GalleryStorage::BYTES_PER_GB);

    expect(GalleryAsset::count())->toBe(1);
});

it('gives a plan that never mentioned gallery storage none of it', function () {
    // The one quota in the enum where silence means zero. Read as unlimited it
    // would hand every plan predating the feature an unmetered disk.
    $tenant = GalleryFixtures::tenant(planGb: 0);
    Sanctum::actingAs($tenant->user);

    $summary = app(GalleryStorage::class)->summary($tenant);

    expect($summary['limit_bytes'])->toBe(0)
        ->and($summary['read_only'])->toBeTrue();

    $this->post('/api/gallery', ['file' => UploadedFile::fake()->image('a.jpg')->size(10)])
        ->assertStatus(422)
        ->assertJsonPath('code', 'gallery_quota_exceeded');
});

it('refuses file types that must never be hosted on the platform domain', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    Sanctum::actingAs($tenant->user);

    $this->post('/api/gallery', ['file' => UploadedFile::fake()->create('payload.html', 4)])
        ->assertStatus(422)
        ->assertJsonPath('code', 'blocked_file_type');

    expect(GalleryAsset::count())->toBe(0);
});

it('frees the space and the bytes when a file is deleted', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    Sanctum::actingAs($tenant->user);

    $created = $this->post('/api/gallery', ['file' => UploadedFile::fake()->image('a.jpg')->size(120)]);
    $asset = GalleryAsset::find($created->json('data.id'));

    $this->delete("/api/gallery/{$asset->id}")->assertOk();

    // A soft delete that kept the file would charge the customer for something
    // the product told them was gone.
    Storage::disk('local')->assertMissing($asset->path);
    expect(app(GalleryStorage::class)->usedBytes($tenant))->toBe(0);
});

it('keeps the public URL working across a rename', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    Sanctum::actingAs($tenant->user);

    $created = $this->post('/api/gallery', ['file' => UploadedFile::fake()->image('catalogo.jpg')->size(80)]);
    $url = $created->json('data.url');

    $this->put("/api/gallery/{$created->json('data.id')}", ['name' => 'Catálogo 2026'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Catálogo 2026')
        // Every message already sent points at this string. A rename that
        // changed it would break all of them to fix a label.
        ->assertJsonPath('data.url', $url);
});

it('serves the file to an unauthenticated fetcher holding the signed link', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    Sanctum::actingAs($tenant->user);

    $created = $this->post('/api/gallery', ['file' => UploadedFile::fake()->image('a.jpg')->size(30)]);

    // Meta and Telegram fetch this with no session — the signature is the
    // credential, exactly as it is for message media.
    app('auth')->forgetGuards();

    $this->get($created->json('data.url'))->assertOk();
});

it('refuses a gallery URL whose signature was tampered with', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    Sanctum::actingAs($tenant->user);

    $created = $this->post('/api/gallery', ['file' => UploadedFile::fake()->image('a.jpg')->size(30)]);
    $asset = GalleryAsset::find($created->json('data.id'));

    $this->get("/gallery/{$asset->uuid}/{$asset->public_filename}")->assertStatus(403);
});

it('never lets one workspace touch another one\'s files', function () {
    $mine = GalleryFixtures::tenant(planGb: 1);
    $theirs = GalleryFixtures::tenant(planGb: 1);
    $asset = GalleryFixtures::asset($theirs, 1_000);

    Sanctum::actingAs($mine->user);

    $this->put("/api/gallery/{$asset->id}", ['name' => 'roubado'])->assertNotFound();
    $this->delete("/api/gallery/{$asset->id}")->assertNotFound();
    $this->get('/api/gallery')->assertOk()->assertJsonCount(0, 'data');
});
