<?php

use App\Services\Media\MediaStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InstagramFixtures;

/**
 * Instagram videos over PHP's one-request limit arrive in pieces.
 *
 * The one-request upload always validated up to 100 MB, but production PHP
 * refuses any request over 80 MB before Laravel sees it — so an 86 MB reel
 * failed with "too large" while the rule beside it said it fitted.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('outbound-staging');
});

// Half-sent pieces live on the real disk (ChunkedUpload::root()), not on a
// faked one; a test that stops mid-upload must not leave its part behind for
// the next test to resume.
afterEach(function () {
    \Illuminate\Support\Facades\File::deleteDirectory(storage_path('app/upload-chunks/instagram'));
});

/** Enough of an MP4 for the content sniffer to call it video/mp4. */
function instagramMp4(): string
{
    return "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 512);
}

function instagramPiece(string $name, string $bytes, array $extra = []): array
{
    return [
        'upload_id' => 'instagram-'.bin2hex(random_bytes(8)),
        'index' => 0,
        'total' => 1,
        'size' => strlen($bytes),
        'name' => $name,
        'chunk' => UploadedFile::fake()->createWithContent($name, $bytes),
        ...$extra,
    ];
}

it('prepares a video that arrived in pieces like a normal upload', function () {
    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);
    Sanctum::actingAs($user);

    $response = $this->post(
        "/api/instagram/accounts/{$connection->id}/uploads/chunks",
        instagramPiece('lancamento.mp4', instagramMp4()),
        ['Accept' => 'application/json'],
    );

    $response->assertCreated()
        ->assertJsonPath('data.done', true)
        ->assertJsonPath('data.media_type', 'video');

    // Published where the scheduled publisher reads it, named as uploaded.
    expect(MediaStorage::published()->exists($response->json('data.path')))->toBeTrue()
        ->and(basename($response->json('data.path')))->toBe('lancamento.mp4')
        ->and(MediaStorage::staging()->allFiles())->toBe([]);
});

it('takes a video up to 100 MB, which one request could never carry', function () {
    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);
    Sanctum::actingAs($user);

    // 86 MB announced: accepted as the first of 11 pieces.
    $size = 86 * 1024 * 1024;
    $piece = str_repeat("\x00", 8 * 1024 * 1024);

    $this->post("/api/instagram/accounts/{$connection->id}/uploads/chunks", instagramPiece('reel.mp4', $piece, [
        'size' => $size,
        'total' => (int) ceil($size / (8 * 1024 * 1024)),
    ]), ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.done', false)
        ->assertJsonPath('data.next_index', 1);
});

it('refuses a video over 100 MB before any byte is kept', function () {
    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);
    Sanctum::actingAs($user);

    $this->post("/api/instagram/accounts/{$connection->id}/uploads/chunks", instagramPiece('longo.mp4', instagramMp4(), [
        'size' => 101 * 1024 * 1024,
        'total' => 13,
    ]), ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('size');

    expect(MediaStorage::staging()->allFiles())->toBe([]);
});

it('refuses a file that is not a video, whatever it is called', function () {
    $user = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($user);
    Sanctum::actingAs($user);

    $this->post(
        "/api/instagram/accounts/{$connection->id}/uploads/chunks",
        instagramPiece('fatura.mp4', '<html><script>alert(1)</script></html>'),
        ['Accept' => 'application/json'],
    )->assertStatus(422);

    expect(MediaStorage::staging()->allFiles())->toBe([])
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('keeps the account gate: no access to the connection, no upload', function () {
    $owner = InstagramFixtures::user();
    $connection = InstagramFixtures::connection($owner);

    $stranger = InstagramFixtures::user();
    Sanctum::actingAs($stranger);

    $this->post(
        "/api/instagram/accounts/{$connection->id}/uploads/chunks",
        instagramPiece('lancamento.mp4', instagramMp4()),
        ['Accept' => 'application/json'],
    )->assertNotFound();
});
