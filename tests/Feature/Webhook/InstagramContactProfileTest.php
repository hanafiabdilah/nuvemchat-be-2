<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Events\ConversationUpdated;
use App\Jobs\SyncContactProfile;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Contact\Profile\ContactProfileSyncer;
use App\Services\Webhook\Handlers\Chat\InstagramHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/** The connected account, and the person it is talking to. */
const IG_ACCOUNT_ID = '17841400000000001';
const IG_CUSTOMER_ID = '9876543210000001';

function igProfileConnection(): Connection
{
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);

    return Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::Instagram,
        'name' => 'Loja oficial',
        'status' => ConnectionStatus::Active,
        'credentials' => [
            'access_token' => 'ig-token',
            'instagram_account_id' => IG_ACCOUNT_ID,
        ],
    ]);
}

/**
 * One messaging event. An echo (the account writing first, from the Instagram
 * app) reverses sender and recipient — which is the whole reason this suite
 * exists.
 */
function igMessagingPayload(bool $isEcho, string $text, string $mid): array
{
    $message = ['mid' => $mid, 'text' => $text];

    if ($isEcho) {
        $message['is_echo'] = true;
    }

    return [
        'id' => IG_ACCOUNT_ID,
        'messaging' => [[
            'sender' => ['id' => $isEcho ? IG_ACCOUNT_ID : IG_CUSTOMER_ID],
            'recipient' => ['id' => $isEcho ? IG_CUSTOMER_ID : IG_ACCOUNT_ID],
            'timestamp' => 1786621132000,
            'message' => $message,
        ]],
    ];
}

/** The contact an outbound-first echo leaves behind: an id, and nothing else. */
function igUnnamedContact(Connection $connection): Contact
{
    return Contact::create([
        'tenant_id' => $connection->tenant_id,
        'external_id' => IG_CUSTOMER_ID,
        'channel' => Channel::Instagram,
        'name' => IG_CUSTOMER_ID,
    ]);
}

test('an echo labels the contact with the recipient id, not the account own id', function () {
    Event::fake();
    Queue::fake();
    $connection = igProfileConnection();

    (new InstagramHandler)->handle($connection, igMessagingPayload(true, 'Oi, tudo bem?', 'mid.echo.1'));

    $contact = Contact::first();

    expect($contact)->not->toBeNull()
        ->and($contact->external_id)->toBe(IG_CUSTOMER_ID)
        // Before, the placeholder came from sender.id — which on an echo is the
        // business account, so every outbound-first thread was named after the
        // account itself.
        ->and($contact->name)->toBe(IG_CUSTOMER_ID);
});

test('the reply queues the lookup that the outbound-first attempt could not make', function () {
    Event::fake();
    Queue::fake();
    $connection = igProfileConnection();
    $contact = igUnnamedContact($connection);

    (new InstagramHandler)->handle($connection, igMessagingPayload(false, 'Tudo, e você?', 'mid.in.1'));

    Queue::assertPushed(
        SyncContactProfile::class,
        fn ($job) => $job->contact->id === $contact->id && $job->channelConnection->id === $connection->id
    );
});

test('a contact that already has a username is left alone', function () {
    Event::fake();
    Queue::fake();
    $connection = igProfileConnection();
    igUnnamedContact($connection)->forceFill([
        'name' => 'Maria Souza',
        'username' => 'maria.souza',
    ])->save();

    (new InstagramHandler)->handle($connection, igMessagingPayload(false, 'Oi de novo', 'mid.in.2'));

    Queue::assertNotPushed(SyncContactProfile::class);
});

test('the lookup names the contact and tells the open dashboards', function () {
    Event::fake();
    $connection = igProfileConnection();
    $contact = igUnnamedContact($connection);

    $conversation = Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => IG_CUSTOMER_ID,
        'status' => ConversationStatus::Pending,
    ]);

    Http::fake([
        'graph.instagram.com/*' => Http::response(['name' => 'Maria Souza', 'username' => 'maria.souza']),
    ]);

    $changed = app(ContactProfileSyncer::class)->sync($contact, $connection);

    expect($changed)->toBeTrue()
        ->and($contact->fresh()->name)->toBe('Maria Souza')
        ->and($contact->fresh()->username)->toBe('maria.souza')
        ->and(ContactProfileSyncer::needsSync($contact->fresh()))->toBeFalse();

    Event::assertDispatched(
        ConversationUpdated::class,
        fn ($event) => $event->conversation->id === $conversation->id
    );
});

test('an account with no display name is named after its username', function () {
    Event::fake();
    $connection = igProfileConnection();
    $contact = igUnnamedContact($connection);

    Http::fake([
        'graph.instagram.com/*' => Http::response(['username' => 'maria.souza']),
    ]);

    app(ContactProfileSyncer::class)->sync($contact, $connection);

    expect($contact->fresh()->name)->toBe('maria.souza');
});

test('a name typed by an agent outranks the one Instagram reports', function () {
    Event::fake();
    $connection = igProfileConnection();
    $contact = igUnnamedContact($connection);
    $contact->forceFill(['name' => 'Cliente VIP', 'name_locked' => true])->save();

    Http::fake([
        'graph.instagram.com/*' => Http::response(['name' => 'Maria Souza', 'username' => 'maria.souza']),
    ]);

    app(ContactProfileSyncer::class)->sync($contact, $connection);

    expect($contact->fresh()->name)->toBe('Cliente VIP')
        ->and($contact->fresh()->username)->toBe('maria.souza');
});

test('a refused lookup keeps the placeholder and paces the next attempt', function () {
    Event::fake();
    $connection = igProfileConnection();
    $contact = igUnnamedContact($connection);

    // What Instagram answers while the person has never written to the account.
    Http::fake([
        'graph.instagram.com/*' => Http::response([
            'error' => ['message' => 'Unsupported get request.', 'code' => 100],
        ], 400),
    ]);

    $changed = app(ContactProfileSyncer::class)->sync($contact, $connection);

    expect($changed)->toBeFalse()
        ->and($contact->fresh()->name)->toBe(IG_CUSTOMER_ID)
        // Stamped, so the next inbound message does not fire a second doomed
        // request — but only until the retry window is over.
        ->and(ContactProfileSyncer::needsSync($contact->fresh()))->toBeFalse();

    $this->travel(ContactProfileSyncer::RETRY_HOURS + 1)->hours();

    expect(ContactProfileSyncer::needsSync($contact->fresh()))->toBeTrue();
});
