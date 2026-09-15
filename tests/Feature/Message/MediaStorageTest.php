<?php

use App\Models\Tenant;
use App\Models\User;
use App\Services\Media\MediaStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Finder\Finder;

uses(RefreshDatabase::class);

test('media stays exactly where it was until a disk is configured', function () {
    expect(MediaStorage::diskName())->toBe('local');
    expect(MediaStorage::outboundDiskName())->toBe('public');
    expect(MediaStorage::publishedDiskName())->toBe('public');
});

test('no code outside MediaStorage names a media disk', function () {
    // The reason moving media to object storage is a config change: a single
    // Storage::disk('local') left behind writes some files to the server's disk
    // and the rest to the bucket, and nothing fails until someone opens one.
    $needles = ["Storage::disk('local')", "Storage::disk('public')", "url('storage/", "asset('storage/"];
    // store()/storeAs()/putFile() take the disk as their last argument.
    $storeCall = "/(store|storeAs|storePublicly|storePubliclyAs|putFile|putFileAs)\\([^;]*'(local|public)'/";

    $offenders = [];

    foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
        if ($file->getRealPath() === realpath(app_path('Services/Media/MediaStorage.php'))) {
            continue;
        }

        $source = $file->getContents();

        foreach ($needles as $needle) {
            if (str_contains($source, $needle)) {
                $offenders[] = $file->getRelativePathname().' → '.$needle;
            }
        }

        if (preg_match($storeCall, $source, $match)) {
            $offenders[] = $file->getRelativePathname().' → '.$match[0];
        }
    }

    expect($offenders)->toBe([]);
});

test('private media follows the configured disk', function () {
    config(['media.disk' => 'media-elsewhere']);
    Storage::fake('media-elsewhere');
    Storage::fake('local');

    MediaStorage::disk()->put('media/photo.jpg', 'bytes');

    Storage::disk('media-elsewhere')->assertExists('media/photo.jpg');
    Storage::disk('local')->assertMissing('media/photo.jpg');
});

test('on the local disk an outbound copy is fetched through /storage on the platform domain', function () {
    expect(MediaStorage::outboundUrl('images/temp_1.jpg'))->toBe(url('storage/images/temp_1.jpg'));
});

test('off the local disk an outbound copy gets a presigned address that outlives the send', function () {
    config([
        'filesystems.disks.objects' => ['driver' => 's3'],
        'media.outbound_disk' => 'objects',
    ]);
    Storage::fake('objects');
    Storage::disk('objects')->buildTemporaryUrlsUsing(
        fn (string $path, $expiration) => "https://objects.test/{$path}?expires={$expiration->timestamp}",
    );

    $this->freezeTime();

    expect(MediaStorage::outboundUrl('images/temp_1.jpg'))
        ->toBe('https://objects.test/images/temp_1.jpg?expires='.now()->addHours(2)->timestamp);
});

test('a published file never gets an address that expires', function () {
    // Flows, campaigns and scheduled Instagram posts store this URL and use it
    // weeks later.
    expect(MediaStorage::publishedUrl('uploads/banner.png'))->toBe(url('storage/uploads/banner.png'));

    config([
        'filesystems.disks.objects' => ['driver' => 's3'],
        'media.published_disk' => 'objects',
    ]);
    Storage::fake('objects');
    Storage::disk('objects')->buildTemporaryUrlsUsing(fn () => 'https://objects.test/presigned');

    expect(MediaStorage::publishedUrl('uploads/banner.png'))
        ->not->toContain('presigned')
        ->toBe(Storage::disk('objects')->url('uploads/banner.png'));
});

test('uploads for flows and campaigns land on the published disk with a lasting address', function () {
    config([
        'media.published_disk' => 'published-test',
        'filesystems.disks.published-test' => ['driver' => 'local'],
    ]);
    Storage::fake('published-test');

    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $response = $this->actingAs($user->fresh())
        ->postJson('/api/uploads', ['file' => UploadedFile::fake()->image('card.png')])
        ->assertOk();

    Storage::disk('published-test')->assertExists($response->json('path'));
    expect($response->json('url'))->toBe(url('storage/'.$response->json('path')));
});
