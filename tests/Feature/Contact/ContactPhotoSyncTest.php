<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Events\ConversationUpdated;
use App\Jobs\SyncContactPhoto;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Contact\Photo\ContactPhotoSyncer;
use App\Services\Webhook\Handlers\Chat\TelegramHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const PHOTO_GROUP_ID = -100555000111;
const PHOTO_USER_ID = 777001;

function photoConnection(Channel $channel = Channel::Telegram): Connection
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);

    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => $channel,
        'name' => 'Telegram',
        'status' => ConnectionStatus::Active,
        'credentials' => ['token' => 'test-token'],
    ]);
}

function photoContact(Connection $connection, array $attributes = []): Contact
{
    return Contact::create(array_merge([
        'tenant_id' => $connection->tenant_id,
        'external_id' => (string) PHOTO_USER_ID,
        'channel' => $connection->channel,
        'name' => 'Alice',
    ], $attributes));
}

beforeEach(function () {
    Storage::fake('local');

    // One stub for the whole test, driven by mutable state: Http::fake() MERGES
    // successive stub lists instead of replacing them, so re-faking mid-test
    // would leave the original (winning) stub in place.
    $this->telegram = (object) [
        'bytes' => 'first-image-bytes',
        'filePath' => 'photos/file_1.jpg',
        'hasPhoto' => true,
        'fails' => false,
    ];

    Http::fake(function ($request) {
        $state = $this->telegram;

        if ($state->fails) {
            return Http::response('boom', 500);
        }

        $url = $request->url();

        return match (true) {
            str_contains($url, 'getUserProfilePhotos') => Http::response([
                'result' => ['photos' => $state->hasPhoto ? [[['file_id' => 'small'], ['file_id' => 'big']]] : []],
            ]),
            str_contains($url, 'getChat') => Http::response([
                'result' => ['photo' => $state->hasPhoto ? ['big_file_id' => 'group-big'] : null],
            ]),
            str_contains($url, 'getFile') => Http::response([
                'result' => ['file_path' => $state->filePath],
            ]),
            str_contains($url, 'api.telegram.org/file/') => Http::response($state->bytes, 200, ['Content-Type' => 'image/jpeg']),
            default => Http::response([], 200),
        };
    });
});

test('a photo is downloaded and stamped on first sync', function () {
    Event::fake();
    $connection = photoConnection();
    $contact = photoContact($connection);

    $changed = app(ContactPhotoSyncer::class)->sync($contact->fresh(), $connection);

    $contact->refresh();

    expect($changed)->toBeTrue()
        ->and($contact->photo_profile)->toStartWith('profile_photos/')
        ->and($contact->photo_synced_at)->not->toBeNull()
        ->and(Storage::disk('local')->get($contact->photo_profile))->toBe('first-image-bytes');
});

test('an unchanged picture is not rewritten and does not broadcast', function () {
    $connection = photoConnection();
    $contact = photoContact($connection);

    $syncer = app(ContactPhotoSyncer::class);
    $syncer->sync($contact->fresh(), $connection);
    $firstPath = $contact->fresh()->photo_profile;

    Event::fake();
    // Same bytes behind a different file_path — every channel hands out a new
    // URL per fetch, so only the content can prove nothing changed.
    $this->telegram->filePath = 'photos/file_2.jpg';

    $changed = $syncer->sync($contact->fresh(), $connection);

    expect($changed)->toBeFalse()
        ->and($contact->fresh()->photo_profile)->toBe($firstPath);
    Event::assertNotDispatched(ConversationUpdated::class);
});

test('a changed picture replaces the file, deletes the old one and broadcasts to open conversations', function () {
    $connection = photoConnection();
    $contact = photoContact($connection);

    $syncer = app(ContactPhotoSyncer::class);
    $syncer->sync($contact->fresh(), $connection);
    $firstPath = $contact->fresh()->photo_profile;

    Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => (string) PHOTO_USER_ID,
        'status' => ConversationStatus::Active,
    ]);

    Event::fake();
    $this->telegram->bytes = 'second-image-bytes';
    $this->telegram->filePath = 'photos/file_2.jpg';

    $changed = $syncer->sync($contact->fresh(), $connection);
    $contact->refresh();

    expect($changed)->toBeTrue()
        ->and($contact->photo_profile)->not->toBe($firstPath)
        ->and(Storage::disk('local')->get($contact->photo_profile))->toBe('second-image-bytes')
        ->and(Storage::disk('local')->exists($firstPath))->toBeFalse();
    Event::assertDispatched(ConversationUpdated::class);
});

test('a failed lookup keeps the stored photo and backs off before retrying', function () {
    Event::fake();
    $connection = photoConnection();
    $contact = photoContact($connection);

    $syncer = app(ContactPhotoSyncer::class);
    $syncer->sync($contact->fresh(), $connection);
    $path = $contact->fresh()->photo_profile;

    $this->telegram->fails = true;

    $changed = $syncer->sync($contact->fresh(), $connection);
    $contact->refresh();

    expect($changed)->toBeFalse()
        ->and($contact->photo_profile)->toBe($path)
        ->and(Storage::disk('local')->exists($path))->toBeTrue()
        // Not retried on the very next inbound message…
        ->and(ContactPhotoSyncer::isStale($contact))->toBeFalse();

    // …but retried once the backoff has elapsed.
    $this->travel(ContactPhotoSyncer::RETRY_HOURS + 1)->hours();

    expect(ContactPhotoSyncer::isStale($contact))->toBeTrue();
});

test('a channel that confirms there is no picture clears the stored one', function () {
    Event::fake();
    $connection = photoConnection();
    $contact = photoContact($connection);

    $syncer = app(ContactPhotoSyncer::class);
    $syncer->sync($contact->fresh(), $connection);
    $path = $contact->fresh()->photo_profile;

    $this->telegram->hasPhoto = false;

    $changed = $syncer->sync($contact->fresh(), $connection);
    $contact->refresh();

    expect($changed)->toBeTrue()
        ->and($contact->photo_profile)->toBeNull()
        ->and($contact->photo_synced_at)->not->toBeNull()
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
});

test('a fresh photo is not re-fetched until the TTL expires', function () {
    $connection = photoConnection();

    $fresh = photoContact($connection, ['photo_synced_at' => now()->subHour()]);
    $stale = photoContact($connection, [
        'external_id' => '777002',
        'photo_synced_at' => now()->subHours(ContactPhotoSyncer::TTL_HOURS + 1),
    ]);
    $never = photoContact($connection, ['external_id' => '777003']);

    expect(ContactPhotoSyncer::isStale($fresh))->toBeFalse()
        ->and(ContactPhotoSyncer::isStale($stale))->toBeTrue()
        ->and(ContactPhotoSyncer::isStale($never))->toBeTrue();
});

test('channels without a photo endpoint are never queued', function () {
    $connection = photoConnection(Channel::WhatsappOfficial);
    $contact = photoContact($connection, ['channel' => Channel::WhatsappOfficial]);

    expect(ContactPhotoSyncer::isStale($contact))->toBeFalse();
});

test('a telegram group photo is read through getChat', function () {
    Event::fake();
    $connection = photoConnection();
    $group = photoContact($connection, [
        'external_id' => (string) PHOTO_GROUP_ID,
        'name' => 'Time de Suporte',
        'is_group' => true,
    ]);
    $this->telegram->bytes = 'group-image-bytes';

    $changed = app(ContactPhotoSyncer::class)->sync($group->fresh(), $connection);

    expect($changed)->toBeTrue()
        ->and(Storage::disk('local')->get($group->fresh()->photo_profile))->toBe('group-image-bytes');
    Http::assertSent(fn ($request) => str_contains($request->url(), 'getChat'));
});

test('new_chat_photo queues a forced resync of the group contact and stores no message', function () {
    Event::fake();
    Queue::fake();
    $connection = photoConnection();

    (new TelegramHandler)->handle($connection, [
        'update_id' => 1,
        'message' => [
            'message_id' => 200,
            'from' => ['id' => PHOTO_USER_ID, 'is_bot' => false, 'first_name' => 'Alice'],
            'chat' => ['id' => PHOTO_GROUP_ID, 'title' => 'Time de Suporte', 'type' => 'supergroup'],
            'date' => 1754500000,
            'new_chat_photo' => [['file_id' => 'a', 'width' => 160, 'height' => 160]],
        ],
    ]);

    $group = Contact::where('external_id', (string) PHOTO_GROUP_ID)->first();

    expect($group)->not->toBeNull()
        ->and($group->is_group)->toBeTrue()
        ->and(Message::count())->toBe(0);
    Queue::assertPushed(SyncContactPhoto::class, fn ($job) => $job->contact->id === $group->id);
});

test('delete_chat_photo clears the stored group photo and stores no message', function () {
    Event::fake();
    $connection = photoConnection();
    $group = photoContact($connection, [
        'external_id' => (string) PHOTO_GROUP_ID,
        'name' => 'Time de Suporte',
        'is_group' => true,
    ]);
    app(ContactPhotoSyncer::class)->sync($group->fresh(), $connection);

    expect($group->fresh()->photo_profile)->not->toBeNull();

    (new TelegramHandler)->handle($connection, [
        'update_id' => 2,
        'message' => [
            'message_id' => 201,
            'from' => ['id' => PHOTO_USER_ID, 'is_bot' => false, 'first_name' => 'Alice'],
            'chat' => ['id' => PHOTO_GROUP_ID, 'title' => 'Time de Suporte', 'type' => 'supergroup'],
            'date' => 1754500000,
            'delete_chat_photo' => true,
        ],
    ]);

    expect($group->fresh()->photo_profile)->toBeNull()
        ->and(Message::count())->toBe(0);
});
