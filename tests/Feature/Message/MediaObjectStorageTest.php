<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Conversation\Type as ConversationType;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Media\MediaStorage;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * An S3 disk as far as MediaStorage can tell — it reads the driver from config
 * — with a local fake underneath, so nothing leaves the machine.
 */
function bucketDisk(string $name = 'media'): void
{
    config(["filesystems.disks.{$name}" => ['driver' => 's3', 'bucket' => 'pingly-media']]);
    Storage::fake($name);
    Storage::disk($name)->buildTemporaryUrlsUsing(
        fn (string $path, $expiration) => "https://ewr1.vultrobjects.com/pingly-media/private/{$path}?X-Amz-Expires={$expiration->getTimestamp()}",
    );
}

function bucketConversation(): Conversation
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);

    $connection = Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappApiway,
        'name' => 'WhatsApp',
        'status' => ConnectionStatus::Active,
    ]);

    $contact = Contact::create([
        'tenant_id' => $tenant->id,
        'external_id' => '5511999999999',
        'name' => 'Ana',
        'channel' => $connection->channel,
    ]);

    return Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => $contact->external_id,
        'type' => ConversationType::Private,
        'status' => ConversationStatus::Active,
    ]);
}

test('a signed media link keeps the exact address and signature of the old storage route', function () {
    // Links cached in dashboards were signed by Laravel's storage.local route;
    // the route that replaces it must accept them byte for byte.
    $this->freezeTime();
    $expires = now()->addDays(30);

    $relative = '/storage/media/1_photo.jpg?expires='.$expires->getTimestamp();
    $signature = hash_hmac('sha256', $relative, config('app.key'));

    expect(MediaStorage::signedUrl('media/1_photo.jpg', $expires))->toBe(url($relative.'&signature='.$signature));
});

test('on the local disk a signed link streams the file, as before', function () {
    Storage::fake('local');
    Storage::disk('local')->put('media/1_photo.jpg', 'jpeg-bytes');

    $response = $this->get(MediaStorage::signedUrl('media/1_photo.jpg', now()->addDay()));

    $response->assertOk();
    expect($response->streamedContent())->toBe('jpeg-bytes');
});

test('a tampered or expired link is refused', function () {
    Storage::fake('local');
    Storage::disk('local')->put('media/1_photo.jpg', 'jpeg-bytes');
    Storage::disk('local')->put('media/2_photo.jpg', 'other-bytes');

    $url = MediaStorage::signedUrl('media/1_photo.jpg', now()->addHour());

    $this->get(str_replace('1_photo', '2_photo', $url))->assertForbidden();

    $this->travel(2)->hours();
    $this->get($url)->assertForbidden();
});

test('on object storage a signed link redirects to a presigned url instead of streaming', function () {
    // Our link lives for months; a presigned one cannot outlive 7 days. So ours
    // stays the long-lived half and the bytes never pass through this server.
    bucketDisk();
    config(['media.disk' => 'media']);
    Storage::disk('media')->put('media/1_photo.jpg', 'jpeg-bytes');
    $this->freezeTime();

    $response = $this->get(MediaStorage::signedUrl('media/1_photo.jpg', now()->addDays(30)));

    // Rounded to the hour, so a browser seeing it again uses its cache.
    $response->assertRedirect(
        'https://ewr1.vultrobjects.com/pingly-media/private/media/1_photo.jpg?X-Amz-Expires='
        .now()->startOfHour()->addHours(2)->getTimestamp()
    );
    expect($response->headers->get('Cache-Control'))->toContain('max-age=600');
});

test('while media is moving, a file not yet in the bucket is still served from the old disk', function () {
    bucketDisk();
    Storage::fake('local');
    config(['media.disk' => 'media', 'media.legacy_disk' => 'local']);

    Storage::disk('local')->put('media/old.jpg', 'old-bytes');
    Storage::disk('media')->put('media/new.jpg', 'new-bytes');

    $old = $this->get(MediaStorage::signedUrl('media/old.jpg', now()->addDay()));
    $old->assertOk();
    expect($old->streamedContent())->toBe('old-bytes');

    $this->get(MediaStorage::signedUrl('media/new.jpg', now()->addDay()))->assertRedirect();
});

test('purging on object storage deletes by the recorded size without asking the bucket first', function () {
    bucketDisk();
    config(['media.disk' => 'media']);

    Storage::disk('media')->put('media/old.jpg', str_repeat('x', 2048));

    $message = bucketConversation()->messages()->create([
        'external_id' => (string) Str::uuid(),
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Image,
        'body' => 'foto',
        'attachment' => 'media/old.jpg',
        'sent_at' => now()->subDays(100),
    ]);
    $message->forceFill(['created_at' => now()->subDays(100)])->save();

    // Measured once, when the file was attached.
    expect($message->fresh()->attachment_size)->toBe(2048);

    $this->artisan('media:purge')->expectsOutputToContain('2.0 KB')->assertSuccessful();

    Storage::disk('media')->assertMissing('media/old.jpg');
    expect($message->fresh()->attachment)->toBeNull();
});

test('orphan widget uploads on object storage are found from a single listing', function () {
    bucketDisk();
    config(['media.disk' => 'media']);

    Storage::disk('media')->put('widget-uploads/tok/orphan.png', 'bytes');
    Storage::disk('media')->put('widget-uploads/tok/fresh.png', 'bytes');
    touch(Storage::disk('media')->path('widget-uploads/tok/orphan.png'), now()->subDays(2)->getTimestamp());

    $this->artisan('media:purge')->assertSuccessful();

    Storage::disk('media')->assertMissing('widget-uploads/tok/orphan.png');
    Storage::disk('media')->assertExists('widget-uploads/tok/fresh.png');
});

test('media:migrate copies only what the target lacks and says when nothing is left', function () {
    Storage::fake('local');
    Storage::fake('objects');

    Storage::disk('local')->put('media/a.jpg', 'aaa');
    Storage::disk('local')->put('media/b.jpg', 'bbb');
    Storage::disk('local')->put('media/c.jpg', 'ccc');
    Storage::disk('local')->put('.gitignore', '*');

    Storage::disk('objects')->put('media/b.jpg', 'bbb'); // already copied
    Storage::disk('objects')->put('media/c.jpg', 'c');   // cut short — size differs

    $this->artisan('media:migrate', ['from' => 'local', 'to' => 'objects', '--dry-run' => true])->assertSuccessful();
    Storage::disk('objects')->assertMissing('media/a.jpg');

    $this->artisan('media:migrate', ['from' => 'local', 'to' => 'objects'])->assertSuccessful();

    expect(Storage::disk('objects')->get('media/a.jpg'))->toBe('aaa')
        ->and(Storage::disk('objects')->get('media/c.jpg'))->toBe('ccc');
    Storage::disk('objects')->assertMissing('.gitignore');

    $this->artisan('media:migrate', ['from' => 'local', 'to' => 'objects', '--dry-run' => true])
        ->expectsOutputToContain('Nothing left to copy')
        ->assertSuccessful();
});

test('media:migrate refuses to copy a disk onto itself', function () {
    $this->artisan('media:migrate', ['from' => 'local', 'to' => 'local'])->assertFailed();
});

test('media:configure-bucket lets dashboards fetch media from the bucket', function () {
    config(['filesystems.disks.media' => ['driver' => 's3', 'bucket' => 'pingly-media']]);

    $handler = new MockHandler([new Result([])]);
    app()->instance('media.s3client', new S3Client([
        'region' => 'us-east-1',
        'version' => 'latest',
        'credentials' => ['key' => 'key', 'secret' => 'secret'],
        'handler' => $handler,
    ]));

    $this->artisan('media:configure-bucket')->assertSuccessful();

    $command = $handler->getLastCommand();

    expect($command->getName())->toBe('PutBucketCors')
        ->and($command['Bucket'])->toBe('pingly-media')
        // After the redirect from our signed link the browser sends Origin: null.
        ->and($command['CORSConfiguration']['CORSRules'][0]['AllowedOrigins'])->toBe(['*'])
        ->and($command['CORSConfiguration']['CORSRules'][0]['AllowedMethods'])->toBe(['GET', 'HEAD']);
});

test('media:configure-bucket refuses a disk that is not on object storage', function () {
    $this->artisan('media:configure-bucket', ['disk' => 'local'])->assertFailed();
});
