<?php

use App\Enums\Gallery\AssetType;
use App\Services\Gallery\GalleryMediaResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\GalleryFixtures;

/**
 * Turning a picked library file into an outbound send.
 *
 * The gallery has no sender of its own — a file resolves to the same
 * `send-image` / `send-video` / … route the composer has always called, with
 * the asset's signed URL as `media_url`. What is tested here is the part that
 * makes an id safer than a URL: the ownership check, and the fact that the URL
 * is minted on this side.
 */
uses(RefreshDatabase::class);

it('turns an owned asset id into its signed URL', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    $asset = GalleryFixtures::asset($tenant, 1_000);

    $data = app(GalleryMediaResolver::class)->apply(
        ['gallery_asset_id' => $asset->id, 'message' => 'olha só'],
        $tenant,
        AssetType::Image,
    );

    expect($data['media_url'])->toBe($asset->publicUrl())
        // The id must not survive into the handler payload: nothing downstream
        // knows what it is, and a stray key in a validated array is a trap.
        ->and($data)->not->toHaveKey('gallery_asset_id')
        ->and($data['message'])->toBe('olha só');

    expect($asset->fresh()->last_used_at)->not->toBeNull();
});

it('refuses an asset belonging to another workspace', function () {
    $mine = GalleryFixtures::tenant(planGb: 1);
    $theirs = GalleryFixtures::tenant(planGb: 1);
    $asset = GalleryFixtures::asset($theirs, 1_000);

    // The whole reason the parameter is an id and not a URL: a client that
    // built the URL itself would be choosing what this server fetches.
    expect(fn () => app(GalleryMediaResolver::class)->apply(
        ['gallery_asset_id' => $asset->id],
        $mine,
        AssetType::Image,
    ))->toThrow(ValidationException::class);
});

it('refuses a file sent down the wrong media route', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);
    $document = GalleryFixtures::asset($tenant, 1_000, type: 'document');

    // Caught here rather than by the channel, which would answer minutes later
    // with an unexplained upload failure.
    expect(fn () => app(GalleryMediaResolver::class)->apply(
        ['gallery_asset_id' => $document->id],
        $tenant,
        AssetType::Image,
    ))->toThrow(ValidationException::class);
});

it('leaves an ordinary upload untouched', function () {
    $tenant = GalleryFixtures::tenant(planGb: 1);

    $data = app(GalleryMediaResolver::class)->apply(['message' => 'oi'], $tenant, AssetType::Image);

    expect($data)->toBe(['message' => 'oi']);
});

it('routes each kind of file to the endpoint that sends it', function () {
    // The composer reads `send_path` off the resource rather than re-deriving
    // it from a MIME string, so the two can never disagree.
    expect(AssetType::Image->sendPath())->toBe('send-image')
        ->and(AssetType::Video->sendPath())->toBe('send-video')
        ->and(AssetType::Audio->sendPath())->toBe('send-audio')
        ->and(AssetType::Document->sendPath())->toBe('send-document');
});

it('classifies a file the browser could not identify by its extension', function () {
    // Phones and desktop clients send application/octet-stream for anything
    // they do not recognise; the extension is all there is left.
    expect(AssetType::classify('application/octet-stream', 'mp4'))->toBe(AssetType::Video)
        ->and(AssetType::classify('image/webp', 'bin'))->toBe(AssetType::Image)
        ->and(AssetType::classify(null, 'pdf'))->toBe(AssetType::Document);
});
