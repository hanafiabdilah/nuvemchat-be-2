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
use App\Models\Flow;
use App\Models\Message;
use App\Models\Tag;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function statsOwner(): User
{
    $user = User::factory()->create(['name' => 'Owner']);
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();

    $role = Role::findOrCreate('owner', 'web');
    foreach (['statistics.tenant.view', 'statistics.agents.view'] as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $user->assignRole($role);

    return $user->fresh();
}

function statsConnection(User $user, Channel $channel = Channel::WhatsappApiway): Connection
{
    $connection = Connection::create([
        'tenant_id' => $user->tenant_id,
        'channel' => $channel,
        'name' => 'Canal ' . $channel->value,
        'color' => '#22c55e',
        'status' => ConnectionStatus::Active,
    ]);

    $user->connections()->syncWithoutDetaching([$connection->id]);

    return $connection;
}

function statsConversation(Connection $connection, array $attributes = []): Conversation
{
    $contact = Contact::create([
        'tenant_id' => $connection->tenant_id,
        'external_id' => 'c' . fake()->unique()->numerify('##########'),
        'name' => fake()->firstName(),
        'channel' => $connection->channel,
    ]);

    return Conversation::create(array_merge([
        'contact_id' => $contact->id,
        'connection_id' => $connection->id,
        'external_id' => $contact->external_id,
        'status' => ConversationStatus::Pending,
    ], $attributes));
}

function statsMessage(Conversation $conversation, SenderType $sender, Carbon $at, ?int $userId = null): Message
{
    $message = Message::create([
        'conversation_id' => $conversation->id,
        'sender_type' => $sender,
        'message_type' => MessageType::Text,
        'body' => 'hello',
        'sent_at' => $at,
        'sent_by_user_id' => $userId,
    ]);

    // created_at is the clock every statistic reads (sent_at can be back-dated
    // by a history import), so the fixture has to set it explicitly.
    $message->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

    return $message->fresh();
}

it('answers every statistics section for an empty tenant', function () {
    $user = statsOwner();

    foreach (['overview', 'volume', 'service', 'agents', 'topics', 'automation', 'health', 'filters'] as $section) {
        $response = $this->actingAs($user)->getJson("/api/statistics/{$section}");

        expect($response->status())->toBe(200, "section {$section} failed");
    }
});

it('reports response rate and first response time instead of counting unassigned as missed', function () {
    $user = statsOwner();
    $connection = statsConnection($user);
    $now = Carbon::now()->subDays(2);

    // Answered by a human two minutes later.
    $answered = statsConversation($connection, ['user_id' => $user->id, 'status' => ConversationStatus::Active]);
    statsMessage($answered, SenderType::Incoming, $now);
    statsMessage($answered, SenderType::Outgoing, $now->copy()->addMinutes(2), $user->id);

    // Never answered by anyone.
    $ignored = statsConversation($connection);
    statsMessage($ignored, SenderType::Incoming, $now);

    // Unassigned, but the flow replied — the old page called this "missed".
    $flow = Flow::create(['name' => 'Atendimento', 'tenant_id' => $user->tenant_id]);
    $automated = statsConversation($connection, ['status' => ConversationStatus::AiHandling]);
    statsMessage($automated, SenderType::Incoming, $now);
    $bot = statsMessage($automated, SenderType::Outgoing, $now->copy()->addMinute());
    $bot->forceFill(['sent_by_flow_id' => $flow->id])->save();

    $data = $this->actingAs($user)
        ->getJson('/api/statistics/overview')
        ->assertOk()
        ->json('data');

    expect($data['current']['conversations'])->toBe(3)
        ->and($data['current']['answered'])->toBe(2)
        ->and($data['current']['unanswered'])->toBe(1)
        ->and($data['current']['response_rate'])->toBe(66.7)
        ->and($data['current']['human_response_rate'])->toBe(33.3)
        // Only the human reply is a "first response"; the bot thread counts as
        // automated instead.
        ->and($data['current']['first_response_median_seconds'])->toBe(120)
        ->and($data['current']['automated_conversations'])->toBe(1);
});

it('credits the first response to the agent who actually sent it', function () {
    $user = statsOwner();
    $other = User::factory()->create(['tenant_id' => $user->tenant_id, 'name' => 'Zoe']);
    $connection = statsConnection($user);
    $at = Carbon::now()->subDay();

    $conversation = statsConversation($connection, ['user_id' => $user->id]);
    statsMessage($conversation, SenderType::Incoming, $at);
    // The higher user id replies first: MIN(sent_by_user_id) would have
    // credited the owner, who only spoke afterwards.
    statsMessage($conversation, SenderType::Outgoing, $at->copy()->addMinutes(5), $other->id);
    statsMessage($conversation, SenderType::Outgoing, $at->copy()->addMinutes(9), $user->id);

    $agents = collect($this->actingAs($user)
        ->getJson('/api/statistics/agents')
        ->assertOk()
        ->json('data.agents'))
        ->keyBy('agent_id');

    expect($agents[$other->id]['first_response_median_seconds'])->toBe(300)
        ->and($agents[$other->id]['first_responses'])->toBe(1)
        ->and($agents[$user->id]['first_responses'])->toBe(0)
        ->and($agents[$user->id]['messages_sent'])->toBe(1);
});

it('buckets hours in the viewer timezone', function () {
    $user = statsOwner();
    $connection = statsConnection($user);

    // 01:00 UTC on a Wednesday is 22:00 on the Tuesday in São Paulo.
    $at = Carbon::parse('2026-08-05 01:00:00', 'UTC');
    $conversation = statsConversation($connection);
    statsMessage($conversation, SenderType::Incoming, $at);

    $cells = collect($this->actingAs($user)
        ->getJson('/api/statistics/volume?' . http_build_query([
            'from' => '2026-08-01',
            'to' => '2026-08-06',
            'timezone' => 'America/Sao_Paulo',
        ]))
        ->assertOk()
        ->json('data.heatmap'))
        ->filter(fn ($cell) => $cell['total'] > 0)
        ->values();

    expect($cells)->toHaveCount(1)
        ->and($cells[0]['hour'])->toBe(22)
        ->and($cells[0]['dow'])->toBe(2); // Tuesday
});

it('excludes group conversations unless asked for them', function () {
    $user = statsOwner();
    $connection = statsConnection($user);
    $at = Carbon::now()->subDay();

    $group = statsConversation($connection, ['type' => ConversationType::Group]);
    statsMessage($group, SenderType::Incoming, $at);

    $private = statsConversation($connection);
    statsMessage($private, SenderType::Incoming, $at);

    expect($this->actingAs($user)->getJson('/api/statistics/overview')->json('data.current.conversations'))
        ->toBe(1);

    expect($this->actingAs($user)->getJson('/api/statistics/overview?include_groups=1')->json('data.current.conversations'))
        ->toBe(2);
});

it('filters by connection, channel and tag', function () {
    $user = statsOwner();
    $whatsapp = statsConnection($user);
    $telegram = statsConnection($user, Channel::Telegram);
    $at = Carbon::now()->subDay();

    $tag = Tag::create(['tenant_id' => $user->tenant_id, 'name' => 'Suporte', 'color' => '#f00']);

    $tagged = statsConversation($whatsapp);
    $tagged->tags()->attach($tag->id);
    statsMessage($tagged, SenderType::Incoming, $at);

    $untagged = statsConversation($telegram);
    statsMessage($untagged, SenderType::Incoming, $at);

    $count = fn (array $query) => $this->actingAs($user)
        ->getJson('/api/statistics/overview?' . http_build_query($query))
        ->json('data.current.conversations');

    expect($count([]))->toBe(2)
        ->and($count(['connection_ids' => [$whatsapp->id]]))->toBe(1)
        ->and($count(['channels' => [Channel::Telegram->value]]))->toBe(1)
        ->and($count(['tag_ids' => [$tag->id]]))->toBe(1);
});

it('measures chat and e-mail as separate inboxes', function () {
    $user = statsOwner();
    $chat = statsConnection($user);
    $email = statsConnection($user, Channel::Email);
    $at = Carbon::now()->subDay();

    // Chat: answered in a minute.
    $chatThread = statsConversation($chat, ['user_id' => $user->id]);
    statsMessage($chatThread, SenderType::Incoming, $at);
    statsMessage($chatThread, SenderType::Outgoing, $at->copy()->addMinute(), $user->id);

    // E-mail: the shared inbox, answered four hours later. Averaged together
    // with the chat thread neither number describes anything real.
    $emailThread = statsConversation($email, ['status' => ConversationStatus::Active]);
    statsMessage($emailThread, SenderType::Incoming, $at);
    statsMessage($emailThread, SenderType::Outgoing, $at->copy()->addHours(4), $user->id);

    $overview = fn (string $scope) => $this->actingAs($user)
        ->getJson('/api/statistics/overview?scope=' . $scope)
        ->assertOk()
        ->json('data.current');

    expect($overview('chat')['conversations'])->toBe(1)
        ->and($overview('chat')['first_response_median_seconds'])->toBe(60)
        ->and($overview('email')['conversations'])->toBe(1)
        ->and($overview('email')['first_response_median_seconds'])->toBe(4 * 3600)
        // Absent scope keeps the old "everything" behaviour for API callers.
        ->and($overview('all')['conversations'])->toBe(2)
        ->and($this->actingAs($user)->getJson('/api/statistics/overview')->json('data.current.conversations'))->toBe(2);

    $channels = collect($this->actingAs($user)
        ->getJson('/api/statistics/volume?scope=chat')
        ->json('data.channels'))
        ->pluck('channel');

    expect($channels)->not->toContain(Channel::Email->value);
});

it('counts only the live queue in the right-now strip', function () {
    $user = statsOwner();
    $connection = statsConnection($user);

    // In the queue: waiting, and the customer wrote this morning.
    $fresh = statsConversation($connection);
    statsMessage($fresh, SenderType::Incoming, Carbon::now()->subHours(3));
    $fresh->forceFill(['last_message_at' => Carbon::now()->subHours(3)])->save();

    // Backlog: pending since forever. Still in the inbox, but no shift is
    // going to answer a message from last year, and counting it as "right
    // now" is what made this number unreadable.
    $stale = statsConversation($connection);
    statsMessage($stale, SenderType::Incoming, Carbon::now()->subMonths(8));
    $stale->forceFill(['last_message_at' => Carbon::now()->subMonths(8)])->save();

    // A Live Chat Widget session opened and abandoned before typing: pending,
    // but invisible in every agent's list, so it is not waiting for anyone.
    statsConversation($connection)->forceFill(['last_message_at' => Carbon::now()])->save();

    $now = $this->actingAs($user)->getJson('/api/statistics/overview')->assertOk()->json('data.now');

    expect($now['pending'])->toBe(1)
        ->and($now['waiting_over_1h'])->toBe(1)
        ->and($now['queue_active_days'])->toBe(7)
        // The period metrics still see every thread that has a message.
        ->and($this->actingAs($user)->getJson('/api/statistics/overview')->json('data.current.conversations'))
        ->toBe(3);
});

it('keeps the contact counts inside the selected inbox', function () {
    $user = statsOwner();
    $chat = statsConnection($user);
    $email = statsConnection($user, Channel::Email);
    $at = Carbon::now()->subDay();

    statsMessage(statsConversation($chat), SenderType::Incoming, $at);

    $emailThread = statsConversation($email, ['status' => ConversationStatus::Active]);
    statsMessage($emailThread, SenderType::Incoming, $at);

    // Somebody in the address book who never wrote: counted straight off the
    // contacts table this looked like a third new customer.
    Contact::create([
        'tenant_id' => $user->tenant_id,
        'external_id' => 'never-wrote',
        'name' => 'Imported',
        'channel' => Channel::WhatsappApiway,
    ]);

    $newContacts = fn (string $scope) => $this->actingAs($user)
        ->getJson('/api/statistics/overview?scope=' . $scope)
        ->assertOk()
        ->json('data.current.contacts_new');

    expect($newContacts('chat'))->toBe(1)
        ->and($newContacts('email'))->toBe(1)
        ->and($newContacts('all'))->toBe(2);
});

it('lists only the selected inbox on the channels board', function () {
    $user = statsOwner();
    statsConnection($user);
    statsConnection($user, Channel::Email);

    $channels = fn (string $scope) => collect($this->actingAs($user)
        ->getJson('/api/statistics/health?scope=' . $scope)
        ->assertOk()
        ->json('data.connections.items'))
        ->pluck('channel')
        ->all();

    expect($channels('chat'))->toBe([Channel::WhatsappApiway->value])
        ->and($channels('email'))->toBe([Channel::Email->value])
        ->and($channels('all'))->toHaveCount(2);
});

it('times resolution from the moment the conversation was closed', function () {
    $user = statsOwner();
    $connection = statsConnection($user);

    $conversation = statsConversation($connection, ['user_id' => $user->id, 'status' => ConversationStatus::Active]);
    $conversation->forceFill(['created_at' => Carbon::now()->subHours(3)])->save();
    statsMessage($conversation, SenderType::Incoming, Carbon::now()->subHours(3));

    $this->actingAs($user)->postJson("/api/conversations/{$conversation->id}/resolve")->assertOk();

    $conversation->refresh();
    expect($conversation->resolved_at)->not->toBeNull()
        ->and($conversation->resolved_by_user_id)->toBe($user->id);

    $current = $this->actingAs($user)->getJson('/api/statistics/overview')->json('data.current');

    expect($current['resolved'])->toBe(1)
        ->and($current['resolution_sample'])->toBe(1)
        ->and($current['resolution_median_seconds'])->toBeGreaterThan(3 * 3600 - 60);
});

it('compares the period against the one before it', function () {
    $user = statsOwner();
    $connection = statsConnection($user);

    // Range is the last 7 days; the previous window is the 7 before that.
    $inside = statsConversation($connection);
    $inside->forceFill(['created_at' => Carbon::now()->subDays(2)])->save();

    $before = statsConversation($connection);
    $before->forceFill(['created_at' => Carbon::now()->subDays(9)])->save();

    $data = $this->actingAs($user)
        ->getJson('/api/statistics/overview?' . http_build_query([
            'from' => Carbon::now()->subDays(6)->toDateString(),
            'to' => Carbon::now()->toDateString(),
        ]))
        ->assertOk()
        ->json('data');

    expect($data['current']['conversations'])->toBe(1)
        ->and($data['previous']['conversations'])->toBe(1)
        ->and($data['range']['previous_to'])->not->toBeNull();
});

it('requires the statistics permissions', function () {
    $user = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $user->id]);
    $user->forceFill(['tenant_id' => $tenant->id])->save();
    Role::findOrCreate('agent', 'web');
    $user->assignRole('agent');

    $this->actingAs($user->fresh())->getJson('/api/statistics/overview')->assertForbidden();
    $this->actingAs($user->fresh())->getJson('/api/statistics/agents')->assertForbidden();
});
