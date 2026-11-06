<?php

use App\Models\GalleryAsset;
use App\Services\Media\MediaStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\GalleryFixtures;

/**
 * Files bigger than PHP takes in one request reach the gallery in pieces.
 *
 * What matters is that the last piece is judged exactly like a normal upload —
 * the blocked types, the allow-list, the quota — because a second door with
 * its own, looser rules is how the HTML-as-PNG hole was opened the first time.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('outbound-staging');
});

function galleryPiece(string $name, string $bytes, array $extra = []): array
{
    return [
        'upload_id' => 'gallery-0123456789abcdef',
        'index' => 0,
        'total' => 1,
        'size' => strlen($bytes),
        'name' => $name,
        'chunk' => UploadedFile::fake()->createWithContent($name, $bytes),
        ...$extra,
    ];
}

it('raises the library ceiling to 200 MB', function () {
    expect(config('gallery.max_upload_mb'))->toBe(200);
});

it('stores a file that arrived in pieces like any other upload', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    Sanctum::actingAs($tenant->user);
    // Held in a variable: the fake's temp file goes when the object does.
    $image = UploadedFile::fake()->image('foto.png', 40, 40);
    $png = file_get_contents($image->getRealPath());

    $this->post('/api/gallery/uploads/chunks', galleryPiece('foto.png', $png), ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.done', true)
        ->assertJsonPath('data.asset.name', 'foto.png')
        ->assertJsonPath('data.duplicate', false);

    expect(GalleryAsset::where('tenant_id', $tenant->id)->count())->toBe(1)
        ->and(MediaStorage::staging()->allFiles())->toBe([]);
});

it('refuses on the first piece when the library has no room for the whole file', function () {
    $tenant = GalleryFixtures::tenant(planGb: 0);
    Sanctum::actingAs($tenant->user);

    $this->post('/api/gallery/uploads/chunks', galleryPiece('video.mp4', 'x', ['size' => 150 * 1024 * 1024, 'total' => 19]), ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'gallery_quota_exceeded');

    expect(MediaStorage::staging()->allFiles())->toBe([]);
});

it('keeps the blocked types out of the pieces door too', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    Sanctum::actingAs($tenant->user);

    // Named like a picture, written like a web page.
    $this->post('/api/gallery/uploads/chunks', galleryPiece('fatura.png', '<html><script>alert(1)</script></html>'), ['Accept' => 'application/json'])
        ->assertStatus(422);

    expect(GalleryAsset::count())->toBe(0)
        ->and(MediaStorage::staging()->allFiles())->toBe([]);
});
