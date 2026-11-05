<?php

use App\Enums\Connection\Channel;
use App\Enums\Connection\Status as ConnectionStatus;
use App\Enums\Conversation\Status as ConversationStatus;
use App\Enums\Message\MessageType;
use App\Enums\Message\SenderType;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * GET /api/messages?since= is the delta every dashboard runs on boot and on
 * every reconnect, with the `server_time` of its previous call: an ISO-8601
 * string carrying a "T" and an offset. Bound as is, MariaDB could not use the
 * updated_at index (2.5 s and 186k rows scanned per call in production on
 * 4 Nov 2026) and SQLite compared it as text, so a row touched later the same
 * day never came back.
 */
beforeEach(fn () => Http::fake());

afterEach(fn () => Carbon::setTestNow());

function deltaSyncWorld(): array
{
    $owner = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $owner->id]);
    $owner->forceFill(['tenant_id' => $tenant->id])->save();
    Role::findOrCreate('owner', 'web');
    $owner->assignRole('owner');

    $connection = Connection::create([
        'tenant_id' => $tenant->id,
        'channel' => Channel::WhatsappApiway,
        'name' => 'Delta',
        'status' => ConnectionStatus::Active,
        'credentials' => ['instance_id' => 'INST-delta', 'token' => 'tok'],
    ]);

    $contact = Contact::create([
        'tenant_id' => $tenant->id,
        'external_id' => '5511999990000',
        'name' => 'Cliente',
        'channel' => Channel::WhatsappApiway,
    ]);

    $conversation = Conversation::create([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => '5511999990000',
        'status' => ConversationStatus::Pending,
        'last_message_at' => now(),
    ]);

    return [$owner->fresh(), $conversation];
}

function deltaSyncMessage(Conversation $conversation, string $body): Message
{
    return Message::create([
        'conversation_id' => $conversation->id,
        'sender_type' => SenderType::Incoming,
        'message_type' => MessageType::Text,
        'body' => $body,
        'sent_at' => now(),
    ]);
}

test('a delta with the server_time of the previous call returns what changed after it', function () {
    Carbon::setTestNow('2026-11-05 10:00:00');
    [$owner, $conversation] = deltaSyncWorld();
    deltaSyncMessage($conversation, 'antes do cursor');

    $cursor = now()->toIso8601String(); // 2026-11-05T10:00:00+00:00

    Carbon::setTestNow('2026-11-05 10:05:00');
    deltaSyncMessage($conversation, 'depois do cursor');

    Sanctum::actingAs($owner);
    $bodies = collect($this->getJson('/api/messages?since='.urlencode($cursor))->assertOk()->json('data'))
        ->pluck('body');

    expect($bodies->all())->toBe(['depois do cursor']);
});

test('the cursor is read as an instant, whatever offset it was written in', function () {
    Carbon::setTestNow('2026-11-05 10:00:00');
    [$owner, $conversation] = deltaSyncWorld();
    deltaSyncMessage($conversation, 'antes do cursor');

    Carbon::setTestNow('2026-11-05 10:05:00');
    deltaSyncMessage($conversation, 'depois do cursor');

    // 10:02 UTC written as Brasília time.
    $cursor = '2026-11-05T07:02:00-03:00';

    Sanctum::actingAs($owner);
    $bodies = collect($this->getJson('/api/messages?since='.urlencode($cursor))->assertOk()->json('data'))
        ->pluck('body');

    expect($bodies->all())->toBe(['depois do cursor']);
});

test('an unreadable cursor falls back to the newest page instead of failing', function () {
    Carbon::setTestNow('2026-11-05 10:00:00');
    [$owner, $conversation] = deltaSyncWorld();
    deltaSyncMessage($conversation, 'oi');

    Sanctum::actingAs($owner);
    $bodies = collect($this->getJson('/api/messages?since=not-a-date')->assertOk()->json('data'))
        ->pluck('body');

    expect($bodies->all())->toBe(['oi']);
});
