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
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/*
 * What a browser that holds nothing asks for on its first sign-in: the threads
 * that last heard something (GET /conversations?order=recent) and the last
 * messages of those threads (GET /messages/recent). Both replace a walk over
 * the whole history that used to stand between a new agent and the inbox.
 */

afterEach(fn () => Carbon::setTestNow());

function firstSyncOwner(): User
{
    $owner = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $owner->id]);
    $owner->forceFill(['tenant_id' => $tenant->id])->save();
    $owner->assignRole(Role::findOrCreate('owner', 'web'));

    return $owner->fresh();
}

function firstSyncConnection(User $owner, string $name = 'WhatsApp'): Connection
{
    return Connection::create([
        'tenant_id' => $owner->tenant_id,
        'channel' => Channel::WhatsappApiway,
        'name' => $name,
        'color' => '#22c55e',
        'status' => ConnectionStatus::Active,
    ]);
}

function firstSyncAgent(Connection ...$connections): User
{
    $agent = User::factory()->create(['tenant_id' => $connections[0]->tenant_id]);
    $agent->connections()->sync(array_map(fn ($c) => $c->id, $connections));

    return $agent->fresh();
}

function firstSyncThread(Connection $connection, array $attributes = []): Conversation
{
    $contact = Contact::create([
        'tenant_id' => $connection->tenant_id,
        'external_id' => (string) fake()->unique()->numerify('55119########'),
        'name' => 'Cliente',
        'channel' => $connection->channel,
    ]);

    return Conversation::create(array_merge([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => $contact->external_id,
        'status' => ConversationStatus::Pending,
    ], $attributes));
}

/** Messages created at the given clock times, oldest first; the last one sets last_message_at. */
function firstSyncSay(Conversation $conversation, string $at, int $count = 1): void
{
    foreach (range(1, $count) as $n) {
        Carbon::setTestNow($at);
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => SenderType::Incoming,
            'message_type' => MessageType::Text,
            'body' => "msg {$n} @ {$at}",
            'sent_at' => now()->timestamp,
        ]);
    }
}

test('order=recent returns the threads that last heard something, not the newest rows', function () {
    $owner = firstSyncOwner();
    $connection = firstSyncConnection($owner);

    // Created first, so the lowest id — but it is the one with the newest message.
    $old = firstSyncThread($connection);
    $middle = firstSyncThread($connection);
    $newest = firstSyncThread($connection);

    firstSyncSay($newest, '2026-11-20 10:00:00');
    firstSyncSay($middle, '2026-11-21 10:00:00');
    firstSyncSay($old, '2026-11-22 10:00:00');

    Sanctum::actingAs($owner);
    $response = $this->getJson('/api/conversations?order=recent&limit=2')->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$old->id, $middle->id])
        ->and($response->json('has_more'))->toBeTrue()
        // No cursor: last_message_at is not a key a walk can resume from.
        ->and($response->json('next_before'))->toBeNull()
        // The convergence lists still ride on it — it is the first page.
        ->and($response->json('connection_ids'))->toBe([(string) $connection->id]);
});

test('order=recent ignores a before cursor', function () {
    $owner = firstSyncOwner();
    $connection = firstSyncConnection($owner);
    $thread = firstSyncThread($connection);
    firstSyncSay($thread, '2026-11-22 10:00:00');

    Sanctum::actingAs($owner);
    $ids = collect($this->getJson('/api/conversations?order=recent&before='.$thread->id)->assertOk()->json('data'))
        ->pluck('id')->all();

    expect($ids)->toBe([$thread->id]);
});

test('order=recent only lists what the agent may see', function () {
    $owner = firstSyncOwner();
    $mine = firstSyncConnection($owner, 'Mine');
    $theirs = firstSyncConnection($owner, 'Theirs');
    $agent = firstSyncAgent($mine);

    $visible = firstSyncThread($mine);
    $hidden = firstSyncThread($theirs);
    firstSyncSay($visible, '2026-11-20 10:00:00');
    firstSyncSay($hidden, '2026-11-22 10:00:00');

    Sanctum::actingAs($agent);
    $ids = collect($this->getJson('/api/conversations?order=recent')->assertOk()->json('data'))->pluck('id')->all();

    expect($ids)->toBe([$visible->id]);
});

test('recent messages are the last N of the most recently active threads', function () {
    $owner = firstSyncOwner();
    $connection = firstSyncConnection($owner);

    $quiet = firstSyncThread($connection);
    $busy = firstSyncThread($connection);
    $busiest = firstSyncThread($connection);

    firstSyncSay($quiet, '2026-11-01 10:00:00', 3);
    firstSyncSay($busy, '2026-11-10 10:00:00', 5);
    firstSyncSay($busiest, '2026-11-20 10:00:00', 5);

    Sanctum::actingAs($owner);
    $response = $this->getJson('/api/messages/recent?threads=2&per_thread=3')->assertOk();

    $byThread = collect($response->json('data'))->groupBy('conversation_id');

    expect($response->json('conversation_ids'))->toBe([(string) $busiest->id, (string) $busy->id])
        ->and($byThread->keys()->sort()->values()->all())->toBe([$busy->id, $busiest->id])
        ->and($byThread[$busiest->id])->toHaveCount(3)
        ->and($byThread[$busy->id])->toHaveCount(3);

    // The newest three of each thread, not the oldest.
    $kept = $byThread[$busiest->id]->pluck('id')->all();
    $newestThree = Message::where('conversation_id', $busiest->id)->orderByDesc('id')->limit(3)->pluck('id')->sort()->values()->all();
    expect($kept)->toBe($newestThree);
});

test('recent messages never include a thread outside the agent connections', function () {
    $owner = firstSyncOwner();
    $mine = firstSyncConnection($owner, 'Mine');
    $theirs = firstSyncConnection($owner, 'Theirs');
    $agent = firstSyncAgent($mine);

    $visible = firstSyncThread($mine);
    $hidden = firstSyncThread($theirs);
    firstSyncSay($visible, '2026-11-20 10:00:00');
    firstSyncSay($hidden, '2026-11-22 10:00:00');

    Sanctum::actingAs($agent);
    $threads = collect($this->getJson('/api/messages/recent')->assertOk()->json('data'))
        ->pluck('conversation_id')->unique()->values()->all();

    expect($threads)->toBe([$visible->id]);
});

test('recent messages leave out an exclusive thread the agent may not read', function () {
    $owner = firstSyncOwner();
    $connection = firstSyncConnection($owner);
    $agent = firstSyncAgent($connection);
    $handler = firstSyncAgent($connection);

    $exclusive = firstSyncThread($connection, [
        'user_id' => $handler->id,
        'status' => ConversationStatus::Active,
        'exclusive_at' => now(),
    ]);
    firstSyncSay($exclusive, '2026-11-22 10:00:00');

    Sanctum::actingAs($agent);
    expect($this->getJson('/api/messages/recent')->assertOk()->json('data'))->toBe([]);

    Sanctum::actingAs($handler);
    expect($this->getJson('/api/messages/recent')->assertOk()->json('data'))->toHaveCount(1);
});

test('recent messages can be scoped to one connection', function () {
    $owner = firstSyncOwner();
    $first = firstSyncConnection($owner, 'First');
    $second = firstSyncConnection($owner, 'Second');

    $a = firstSyncThread($first);
    $b = firstSyncThread($second);
    firstSyncSay($a, '2026-11-20 10:00:00');
    firstSyncSay($b, '2026-11-22 10:00:00');

    Sanctum::actingAs($owner);
    $threads = collect($this->getJson('/api/messages/recent?connection_id='.$first->id)->assertOk()->json('data'))
        ->pluck('conversation_id')->unique()->values()->all();

    expect($threads)->toBe([$a->id]);
});

test('recent messages skip removed groups and answer empty for an empty workspace', function () {
    $owner = firstSyncOwner();
    $connection = firstSyncConnection($owner);

    Sanctum::actingAs($owner);
    $this->getJson('/api/messages/recent')->assertOk()->assertJson(['data' => [], 'conversation_ids' => []]);

    $group = firstSyncThread($connection);
    $group->contact->update(['is_group' => true, 'group_removed_at' => now()]);
    firstSyncSay($group, '2026-11-22 10:00:00');

    expect($this->getJson('/api/messages/recent')->assertOk()->json('data'))->toBe([]);
});
